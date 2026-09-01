<?php

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use App\Services\BatchInstallmentPaymentService;
use App\Services\LoanPaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->service = app(BatchInstallmentPaymentService::class);
    // Pelunasan sebaris kini ditegakkan otoritasnya di dalam service juga.
    $this->user = asSuperAdmin();
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

it('settles a loan early when a row is flagged settle_early', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 12);

    // payoff default = sisa pokok 12jt + 1× jasa 78rb.
    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'loan_id' => $loan->id, 'settle_early' => true, 'amount_paid' => '12078000'],
    ], $this->user->id);

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 0])
        ->and($loan->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and(Installment::where('loan_id', $loan->id)->where('is_settlement', true)->count())->toBe(1)
        ->and(InstallmentSchedule::where('loan_id', $loan->id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)->count())->toBe(0);
});

it('fails the whole batch when a settlement amount is below the payoff', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 12);

    expect(fn () => $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'loan_id' => $loan->id, 'settle_early' => true, 'amount_paid' => '12077999'],
    ], $this->user->id))->toThrow(InvalidArgumentException::class);

    expect($loan->fresh()->status)->toBe(LoanStatus::Cair)
        ->and(Installment::where('loan_id', $loan->id)->count())->toBe(0);
});

it('pays one installment per row and marks the schedule terbayar', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 2);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 0])
        ->and($schedules[0]->fresh()->status)->toBe(InstallmentScheduleStatus::Terbayar)
        ->and($schedules[1]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar)
        ->and(Installment::where('loan_id', $loan->id)->count())->toBe(1)
        ->and(Installment::first()->payment_method)->toBe('potong_gaji');
});

it('attaches the per-row bukti from disk to the installment, then removes the tmp file', function () {
    $disk = config('media-library.disk_name');
    Storage::fake($disk);

    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 1);

    // Simulasikan file yang sudah ditaruh FileUpload (getState) di disk media.
    $path = UploadedFile::fake()->image('bukti.jpg')->storeAs('tmp/installment-bukti', 'bukti.jpg', ['disk' => $disk]);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000', 'bukti_path' => $path, 'bukti_disk' => $disk],
    ], $this->user->id);

    $installment = Installment::where('loan_id', $loan->id)->firstOrFail();

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 0])
        ->and($installment->hasMedia('bukti'))->toBeTrue()
        // addMediaFromDisk memindahkan file → tmp tak menyisa.
        ->and(Storage::disk($disk)->exists($path))->toBeFalse();
});

it('records the installment even when the row carries no bukti', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 1);

    $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000', 'bukti_path' => null],
    ], $this->user->id);

    $installment = Installment::where('loan_id', $loan->id)->firstOrFail();

    expect($installment->hasMedia('bukti'))->toBeFalse();
});

it('skips a schedule that is already terbayar without creating a duplicate', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 1);
    $schedules[0]->update(['status' => 'Terbayar']);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result)->toMatchArray(['created' => 0, 'skipped' => 1])
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
        ->and($a[0]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar);
});

it('records overpayment as Lain-lain without inflating principal or tabungan berjangka', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 2);

    $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1200000'], // lebih 110.000
    ], $this->user->id);

    $inst = Installment::where('loan_id', $loan->id)->first();

    expect($inst->breakdown()['credit_reserved'])->toBe('110000.00')
        ->and($loan->fresh()->remainingPrincipal())->toBe('11000000.00'); // 12jt − 1jt pokok
});

it('auto-settles the loan and refunds SWP + tabungan berjangka on the final installment', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 1);
    // Metode refund diwarisi dari pinjaman (disbursement_method), bukan argumen batch.
    $loan->update(['disbursement_method' => 'transfer']);

    $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($loan->fresh()->status)->toBe(LoanStatus::Lunas);

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
        // Payload batch kini dibungkus `attributes` — satu-satunya kunci yang
        // benar-benar dirender panel audit maupun ActivityResource (R22).
        ->and($batch->properties['attributes']['created'])->toBe(2)
        ->and($batch->properties['attributes']['agency_id'])->toBe($this->agency->id);
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

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 1])
        ->and($okSch[0]->fresh()->status)->toBe(InstallmentScheduleStatus::Terbayar)
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

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 1])
        // Jadwal OPD lain TIDAK pernah dibayar lewat batch OPD ini.
        ->and(Installment::where('loan_id', $foreignLoan->id)->count())->toBe(0)
        ->and($foreign[0]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar);
});

it('rejects an empty batch', function () {
    expect(fn () => $this->service->run($this->agency, '2026-06-01', [], $this->user->id))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * Item 3n (ADR 2026-08-28) — REGRESI BATCH. ADR menyatakan jalur potong gaji
 * "tidak disentuh sama sekali" dan `Δtitipan = 0` karena payroll selalu memotong
 * tepat sebesar tagihan KONTRAK. Klaim itu diuji di sini, bukan sekadar ditulis.
 */
it('does not move the titipan at all on a payroll batch', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 3);
    $loan->update(['principal_amount' => 3000000, 'term_months' => 3]);

    // Titipan 990.000 dari kelebihan bayar manual bulan sebelumnya.
    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 2080000], $this->user->id);

    $before = $loan->fresh()->overpaymentCredit();

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[1]->id, 'loan_id' => $loan->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    $row = Installment::where('schedule_id', $schedules[1]->id)->firstOrFail();

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 0])
        ->and($before)->toBe('990000.00')
        // Δ = uang diterima − tagihan kontrak = 0. Titipan tidak bergerak.
        ->and($loan->fresh()->overpaymentCredit())->toBe('990000.00')
        ->and($row->amount_paid)->toBe('1090000.00')
        ->and($row->credit_applied)->toBe('0.00');
});

/**
 * R23 — penjaga Pelunasan Dipercepat TIDAK boleh menyala di jalur potong gaji.
 *
 * Bila menyala, `pay()` melempar dan batch menelannya diam-diam lewat
 * `catch (CannotProcessPayment) { $skipped++; }`: gaji anggota sudah terpotong
 * tapi angsurannya tak pernah tercatat. Kondisi pemicunya sempit tapi nyata —
 * titipan cukup besar + dua angsuran tersisa membuat jumlah pelunasan turun di
 * bawah tagihan kontrak satu bulan.
 */
it('never silently drops a payroll deduction because of the settlement guard', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 3);
    $loan->update(['principal_amount' => 3000000, 'term_months' => 3]);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 2080000], $this->user->id);

    // Jumlah pelunasan (1.088.000) kini DI BAWAH tagihan kontrak (1.090.000).
    expect($loan->fresh()->payoffAmount())->toBe('1088000.00');

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[1]->id, 'loan_id' => $loan->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 0])
        ->and(Installment::where('schedule_id', $schedules[1]->id)->exists())->toBeTrue()
        // Tetap angsuran biasa — BUKAN dibelokkan jadi pelunasan.
        ->and(Installment::where('loan_id', $loan->id)->where('is_settlement', true)->exists())->toBeFalse();
});

it('behaves exactly as before for a loan that never had titipan', function () {
    [$loan, $schedules] = loanWithSchedules($this->agency->id, count: 3);
    $loan->update(['principal_amount' => 3000000, 'term_months' => 3]);

    $result = $this->service->run($this->agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'loan_id' => $loan->id, 'amount_paid' => '1090000'],
    ], $this->user->id);

    $row = Installment::where('schedule_id', $schedules[0]->id)->firstOrFail();

    expect($result)->toMatchArray(['created' => 1, 'skipped' => 0])
        ->and($row->amount_paid)->toBe('1090000.00')
        ->and($loan->fresh()->overpaymentCredit())->toBe('0.00');
});
