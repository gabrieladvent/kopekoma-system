<?php

use App\Filament\Pages\BatchInstallmentPayment;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * Anggota Aktif OPD ini dengan satu pinjaman jangka panjang Cair + N jadwal.
 *
 * @return array{0: Member, 1: Loan, 2: Collection<int, InstallmentSchedule>}
 */
function memberWithLoan(string $agencyId, int $schedules = 1): array
{
    $member = Member::factory()->create(['agency_id' => $agencyId, 'status' => 'Aktif']);
    $loan = Loan::factory()->create(['member_id' => $member->id]);

    $rows = collect(range(1, $schedules))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
    ]));

    return [$member, $loan, $rows];
}

// ── Akses (enforcement, bukan sekadar sembunyikan tombol) ──────────────

it('denies the page to a user without the batch permission', function () {
    asPetugas();
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    expect(BatchInstallmentPayment::canAccess())->toBeFalse();
});

it('grants access to petugas, pengurus and super admin', function () {
    expect(BatchInstallmentPayment::canAccess())->toBeFalse();

    asPetugas();
    expect(BatchInstallmentPayment::canAccess())->toBeTrue();

    asPengurus();
    expect(BatchInstallmentPayment::canAccess())->toBeTrue();

    asSuperAdmin();
    expect(BatchInstallmentPayment::canAccess())->toBeTrue();
});

// ── Alur halaman ──────────────────────────────────────────────────────

it('loads only OPD members with active loans, offering the oldest unpaid schedule (FIFO)', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, , $schedules] = memberWithLoan($agency->id, schedules: 2);
    // Anggota tanpa pinjaman aktif → tak dimuat.
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif']);

    $component = Livewire::test(BatchInstallmentPayment::class)
        ->set('data.agency_id', $agency->id);

    $rows = $component->get('data')['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['include'])->toBeTrue()
        ->and($rows[0]['lines'])->toHaveCount(1);

    $line = $rows[0]['lines'][0];

    // FIFO: jadwal yang ditawarkan = seq terkecil yang belum bayar.
    expect($line['schedule_id'])->toBe($schedules[0]->id)
        // prefill bilangan bulat bersih (bukan "1090000.00").
        ->and($line['amount'])->toBe('1090000')
        ->and($line['include'])->toBeTrue();
});

it('processes the batch and records installments only for included members and loans', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, $loanA, $schA] = memberWithLoan($agency->id);
    [, $loanB, $schB] = memberWithLoan($agency->id);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->set('data.payment_date', '2026-06-10')
        ->set('data.refund_method', 'tunai')
        ->set('data.rows', [
            ['member_id' => $loanA->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loanA->id, 'schedule_id' => $schA[0]->id, 'total_due' => '1090000', 'loan_label' => 'A', 'include' => true, 'amount' => '1090000'],
            ]],
            ['member_id' => $loanB->member_id, 'member_label' => 'b', 'include' => false, 'lines' => [
                ['loan_id' => $loanB->id, 'schedule_id' => $schB[0]->id, 'total_due' => '1090000', 'loan_label' => 'B', 'include' => true, 'amount' => '1090000'],
            ]],
        ])
        ->call('process')
        ->assertNotified();

    expect(Installment::count())->toBe(1)
        ->and(Installment::where('loan_id', $loanA->id)->exists())->toBeTrue()
        ->and(Installment::where('loan_id', $loanB->id)->exists())->toBeFalse()
        ->and($schA[0]->fresh()->status)->toBe('Terbayar');
});

it('skips a line whose toggle is off', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, $loan, $sch] = memberWithLoan($agency->id);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->set('data.rows', [
            ['member_id' => $loan->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loan->id, 'schedule_id' => $sch[0]->id, 'total_due' => '1090000', 'loan_label' => 'A', 'include' => false, 'amount' => '1090000'],
            ]],
        ])
        ->call('process')
        ->assertNotified();

    expect(Installment::count())->toBe(0);
});

it('warns and creates nothing when no member is selected', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, $loan, $sch] = memberWithLoan($agency->id);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->set('data.rows', [
            ['member_id' => $loan->member_id, 'member_label' => 'a', 'include' => false, 'lines' => [
                ['loan_id' => $loan->id, 'schedule_id' => $sch[0]->id, 'total_due' => '1090000', 'loan_label' => 'A', 'include' => true, 'amount' => '1090000'],
            ]],
        ])
        ->call('process')
        ->assertNotified();

    expect(Installment::count())->toBe(0);
});
