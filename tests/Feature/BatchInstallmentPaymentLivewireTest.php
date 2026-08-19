<?php

use App\Enums\InstallmentScheduleStatus;
use App\Livewire\Loan\Installment\BatchInstallmentPayment;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function lwMemberWithLoan(string $agencyId, int $schedules = 1): array
{
    $member = Member::factory()->create(['agency_id' => $agencyId, 'status' => 'Aktif']);
    $loan = Loan::factory()->create(['member_id' => $member->id]);

    $rows = collect(range(1, $schedules))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
    ]));

    return [$member, $loan, $rows];
}

it('settles a loan early from the batch when confirmed by an authorized pengurus', function () {
    asPengurus();
    $agency = Agency::factory()->create();
    [, $loan, $sch] = lwMemberWithLoan($agency->id, schedules: 12);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->set('rows', [
            ['member_id' => $loan->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loan->id, 'schedule_id' => $sch[0]->id, 'total_due' => '1090000',
                    'settle_early' => true, 'include' => true, 'amount' => '12078000'],
            ]],
        ])
        ->set('confirm_settlement', true)
        ->call('process');

    expect($loan->fresh()->status->value)->toBe('Lunas');
});

it('requires explicit confirmation before processing settlement rows', function () {
    asPengurus();
    $agency = Agency::factory()->create();
    [, $loan, $sch] = lwMemberWithLoan($agency->id, schedules: 12);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->set('rows', [
            ['member_id' => $loan->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loan->id, 'schedule_id' => $sch[0]->id, 'total_due' => '1090000',
                    'settle_early' => true, 'include' => true, 'amount' => '12078000'],
            ]],
        ])
        ->set('confirm_settlement', false)
        ->call('process');

    expect($loan->fresh()->status->value)->toBe('Cair'); // belum dikonfirmasi → tak diproses
});

it('forbids batch settlement for petugas even if the flag is injected', function () {
    asPetugas();
    $agency = Agency::factory()->create();
    [, $loan, $sch] = lwMemberWithLoan($agency->id, schedules: 12);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->set('rows', [
            ['member_id' => $loan->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loan->id, 'schedule_id' => $sch[0]->id, 'total_due' => '1090000',
                    'settle_early' => true, 'include' => true, 'amount' => '12078000'],
            ]],
        ])
        ->set('confirm_settlement', true)
        ->call('process')
        ->assertStatus(403);

    expect($loan->fresh()->status->value)->toBe('Cair');
});

it('denies the Livewire batch page to a user without the permission', function () {
    asPetugas();
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    Livewire::test(BatchInstallmentPayment::class)->assertStatus(403);
});

it('loads OPD members with active loans, offering the oldest unpaid schedule (FIFO) and prefilling the bill', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [,, $schedules] = lwMemberWithLoan($agency->id, schedules: 2);
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif']); // tanpa pinjaman → tak dimuat

    $component = Livewire::test(BatchInstallmentPayment::class)->set('agency_id', $agency->id);

    $rows = $component->get('rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['include'])->toBeTrue()
        ->and($rows[0]['lines'])->toHaveCount(1);

    $line = $rows[0]['lines'][0];

    expect($line['schedule_id'])->toBe($schedules[0]->id)
        ->and($line['amount'])->toBe('1090000')
        ->and($line['include'])->toBeTrue();
});

it('processes the batch and records installments only for included members and loans', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, $loanA, $schA] = lwMemberWithLoan($agency->id);
    [, $loanB, $schB] = lwMemberWithLoan($agency->id);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06')
        ->set('payment_date', '2026-06-10')
        ->set('rows', [
            ['member_id' => $loanA->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loanA->id, 'schedule_id' => $schA[0]->id, 'total_due' => '1090000', 'include' => true, 'amount' => '1090000'],
            ]],
            ['member_id' => $loanB->member_id, 'member_label' => 'b', 'include' => false, 'lines' => [
                ['loan_id' => $loanB->id, 'schedule_id' => $schB[0]->id, 'total_due' => '1090000', 'include' => true, 'amount' => '1090000'],
            ]],
        ])
        ->call('process');

    expect(Installment::count())->toBe(1)
        ->and(Installment::where('loan_id', $loanA->id)->exists())->toBeTrue()
        ->and(Installment::where('loan_id', $loanB->id)->exists())->toBeFalse()
        ->and($schA[0]->fresh()->status)->toBe(InstallmentScheduleStatus::Terbayar);
});

it('attaches the per-line bukti to the recorded installment', function () {
    Storage::fake(config('media-library.disk_name'));
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, $loan, $sch] = lwMemberWithLoan($agency->id);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06')
        ->set('payment_date', '2026-06-10')
        ->set('rows', [
            ['member_id' => $loan->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loan->id, 'schedule_id' => $sch[0]->id, 'total_due' => '1090000', 'include' => true, 'amount' => '1090000'],
            ]],
        ])
        ->set('bukti.'.$sch[0]->id, UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))
        // Upload memicu recompute ringkasan di klien agar total tak ter-reset ke 0.
        ->assertDispatched('rows-updated')
        ->call('process');

    $installment = Installment::where('loan_id', $loan->id)->firstOrFail();

    expect($installment->hasMedia('bukti'))->toBeTrue();
});

it('skips a line whose toggle is off', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, $loan, $sch] = lwMemberWithLoan($agency->id);

    Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06')
        ->set('rows', [
            ['member_id' => $loan->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loan->id, 'schedule_id' => $sch[0]->id, 'total_due' => '1090000', 'include' => false, 'amount' => '1090000'],
            ]],
        ])
        ->call('process');

    expect(Installment::count())->toBe(0);
});

it('computes the grand total of only the included lines', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    [, $loanA, $schA] = lwMemberWithLoan($agency->id);
    [, $loanB, $schB] = lwMemberWithLoan($agency->id);

    $component = Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->set('rows', [
            ['member_id' => $loanA->member_id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['loan_id' => $loanA->id, 'schedule_id' => $schA[0]->id, 'total_due' => '1090000', 'include' => true, 'amount' => '1200000'],
            ]],
            ['member_id' => $loanB->member_id, 'member_label' => 'b', 'include' => false, 'lines' => [
                ['loan_id' => $loanB->id, 'schedule_id' => $schB[0]->id, 'total_due' => '1090000', 'include' => true, 'amount' => '1090000'],
            ]],
        ]);

    expect($component->instance()->grandTotal())->toBe(1200000);
});
