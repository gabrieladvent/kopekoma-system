<?php

use App\Models\Agency;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\BatchInstallmentPaymentService;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->service = app(BatchInstallmentPaymentService::class);
    $this->user = User::factory()->create();
    $this->agency = Agency::factory()->create();
});

/**
 * Pinjaman jangka panjang Cair milik anggota OPD ini, dengan N jadwal identik
 * (total_due 1.090.000). Mengembalikan [loan, schedules].
 *
 * @return array{0: Loan, 1: Collection<int, InstallmentSchedule>}
 */
function loanWithSchedules(string $agencyId, int $count = 1): array
{
    $member = Member::factory()->create(['agency_id' => $agencyId, 'status' => 'Aktif']);
    $loan = Loan::factory()->create(['member_id' => $member->id]);

    $schedules = collect(range(1, $count))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
    ]));

    return [$loan, $schedules];
}

it('pays one installment per row and marks the schedule terbayar', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 2);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result)->toBe(['created' => 1, 'skipped' => 0])
        ->and($schedules[0]->fresh()->status)->toBe('Terbayar')
        ->and($schedules[1]->fresh()->status)->toBe('Belum Bayar')
        ->and(Installment::where('loan_id', $loan->id)->count())->toBe(1)
        ->and(Installment::first()->payment_method)->toBe('potong_gaji');
});

it('skips a schedule that is already terbayar without creating a duplicate', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 1);
    $schedules[0]->update(['status' => 'Terbayar']);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result)->toBe(['created' => 0, 'skipped' => 1])
        ->and(Installment::where('loan_id', $loan->id)->count())->toBe(0);
});

it('aborts the whole batch when any row is below the bill (anti-corruption)', function () {
    [, $a] = loanWithSchedules($this->agency->id, count: 1);
    [, $b] = loanWithSchedules($this->agency->id, count: 1);

    expect(fn () => $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $a[0]->id, 'amount_paid' => '1090000'],
        ['schedule_id' => $b[0]->id, 'amount_paid' => '1089999'], // < tagihan
    ], $this->user->id))->toThrow(InvalidArgumentException::class);

    // Atomic: tidak ada satu pun installment dibuat (baris valid pun ikut batal).
    expect(Installment::count())->toBe(0)
        ->and($a[0]->fresh()->status)->toBe('Belum Bayar');
});

it('records overpayment as Lain-lain without inflating principal or tabungan berjangka', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 2);

    $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1200000'], // lebih 110.000
    ], $this->user->id);

    $inst = Installment::where('loan_id', $loan->id)->first();

    expect($inst->breakdown()['other'])->toBe('110000.00')
        ->and($loan->fresh()->remainingPrincipal())->toBe('11000000.00'); // 12jt − 1jt pokok
});

it('auto-settles the loan and refunds SWP + tabungan berjangka on the final installment', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 1);
    // Metode refund diwarisi dari pinjaman (disbursement_method), bukan argumen batch.
    $loan->update(['disbursement_method' => 'transfer']);

    $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($loan->fresh()->status)->toBe('Lunas');

    $refunds = SavingsWithdrawal::where('related_loan_id', $loan->id)->get();

    expect($refunds->pluck('savings_type')->sort()->values()->all())->toBe(['swp', 'tabungan_berjangka'])
        ->and($refunds->firstWhere('savings_type', 'swp')->disbursement_method)->toBe('transfer');
});

it('processes many members of one OPD and logs a single batch activity', function () {
    [, $a] = loanWithSchedules($this->agency->id, count: 1);
    [, $b] = loanWithSchedules($this->agency->id, count: 1);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $a[0]->id, 'amount_paid' => '1090000'],
        ['schedule_id' => $b[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result['created'])->toBe(2);

    $batch = Activity::where('event', 'batch_angsuran_potong_gaji')->first();

    expect($batch)->not->toBeNull()
        ->and($batch->properties['created'])->toBe(2)
        ->and($batch->properties['agency_id'])->toBe($this->agency->id);
});

it('skips a row whose loan is no longer Cair and still commits the valid rows', function () {
    // Pinjaman valid (Cair) + pinjaman yang sudah Lunas tapi jadwalnya masih
    // "Belum Bayar" (anomali/race) → pay() lempar loanNotActive di tengah batch;
    // tertangkap sebagai skip, baris valid tetap commit (savepoint, bukan rollback total).
    [$ok, $okSch] = loanWithSchedules($this->agency->id, count: 1);
    [$lunas, $lunasSch] = loanWithSchedules($this->agency->id, count: 1);
    $lunas->update(['status' => 'Lunas']);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $lunasSch[0]->id, 'amount_paid' => '1090000'],
        ['schedule_id' => $okSch[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result)->toBe(['created' => 1, 'skipped' => 1])
        ->and($okSch[0]->fresh()->status)->toBe('Terbayar')
        ->and(Installment::where('loan_id', $ok->id)->count())->toBe(1)
        ->and(Installment::where('loan_id', $lunas->id)->count())->toBe(0);
});

it('fail-closed: skips a schedule belonging to a member of another OPD (per-OPD invariant)', function () {
    [, $mine] = loanWithSchedules($this->agency->id, count: 1);

    $otherAgency = Agency::factory()->create();
    [$foreignLoan, $foreign] = loanWithSchedules($otherAgency->id, count: 1);

    // schedule_id "asing" diselipkan (mis. payload Livewire diutak-atik).
    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $mine[0]->id, 'amount_paid' => '1090000'],
        ['schedule_id' => $foreign[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result)->toBe(['created' => 1, 'skipped' => 1])
        // Jadwal OPD lain TIDAK pernah dibayar lewat batch OPD ini.
        ->and(Installment::where('loan_id', $foreignLoan->id)->count())->toBe(0)
        ->and($foreign[0]->fresh()->status)->toBe('Belum Bayar');
});

it('rejects an empty batch', function () {
    expect(fn () => $this->service->run($this->agency, '2026-06-01', [], $this->user->id))
        ->toThrow(InvalidArgumentException::class);
});
