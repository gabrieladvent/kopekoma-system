<?php

use App\Filament\Resources\InstallmentResource;
use App\Filament\Resources\InstallmentResource\Pages\CreateInstallment;
use App\Filament\Resources\InstallmentResource\Pages\ViewInstallment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    asSuperAdmin();
    $this->member = Member::factory()->create();
    $this->loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 12000000,
        'swp_amount' => 120000,
        'term_months' => 1,
        'monthly_principal' => 1000000,
        'monthly_interest' => 78000,
        'monthly_time_deposit' => 12000,
    ]);
    $this->schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $this->loan->id,
        'installment_seq' => 1,
        'principal_due' => 1000000,
        'interest_due' => 78000,
        'time_deposit_due' => 12000,
        'total_due' => 1090000,
    ]);
});

it('records a payment through the service, settles the loan and refunds (final installment)', function () {
    Livewire::test(CreateInstallment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_id' => $this->loan->id,
            'schedule_id' => $this->schedule->id,
            'payment_method' => 'manual',
            'amount_paid' => '1090000',
            'payment_date' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Installment::where('loan_id', $this->loan->id)->where('is_reversal', false)->count())->toBe(1)
        ->and($this->schedule->fresh()->status)->toBe('Terbayar')
        ->and($this->loan->fresh()->status)->toBe('Lunas')
        ->and(SavingsWithdrawal::where('related_loan_id', $this->loan->id)->where('savings_type', 'swp')->where('amount', '120000.00')->exists())->toBeTrue();
});

it('prefills the total bill as integer rupiah, not scaled by decimals', function () {
    Livewire::test(CreateInstallment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_id' => $this->loan->id,
            'schedule_id' => $this->schedule->id,
        ])
        ->assertFormSet([
            'amount_paid' => '1090000',
        ]);
});

it('shows the readonly bill detail (Pokok/Jasa/Tab/Total) once a schedule is picked', function () {
    Livewire::test(CreateInstallment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_id' => $this->loan->id,
            'schedule_id' => $this->schedule->id,
        ])
        ->assertSee('Rincian Tagihan')
        ->assertSee('Total Tagihan')
        ->assertSee('Tabungan Berjangka');
});

it('shows a placeholder on the view page when no bukti was uploaded', function () {
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);

    Livewire::test(ViewInstallment::class, ['record' => $inst->getRouteKey()])
        ->assertSee('Bukti Pembayaran')
        ->assertSee('Tidak ada bukti yang diunggah');
});

it('renders the image bukti inline with a link to open it full-size in a new tab', function () {
    Storage::fake(config('media-library.disk_name'));
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);
    $inst->addMedia(UploadedFile::fake()->image('bukti.jpg'))->toMediaCollection('bukti');

    Livewire::test(ViewInstallment::class, ['record' => $inst->getRouteKey()])
        ->assertSee('<img', escape: false)
        ->assertSee('buka ukuran penuh')
        ->assertSee('target="_blank"', escape: false)
        ->assertDontSee('Buka bukti (PDF)');
});

it('renders an open-in-new-tab link when the bukti is a PDF', function () {
    Storage::fake(config('media-library.disk_name'));
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);
    $inst->addMedia(UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'))->toMediaCollection('bukti');

    Livewire::test(ViewInstallment::class, ['record' => $inst->getRouteKey()])
        ->assertSee('Buka bukti (PDF) di tab baru')
        ->assertSee('target="_blank"', escape: false);
});

it('streams a kuitansi angsuran PDF', function () {
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);

    expect(InstallmentResource::printReceipt($inst))->toBeInstanceOf(StreamedResponse::class);
});

it('labels the overpayment breakdown line as Kelebihan Bayar (not Lain-lain)', function () {
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);

    Livewire::test(ViewInstallment::class, ['record' => $inst->getRouteKey()])
        ->assertSee('Kelebihan Bayar')
        ->assertDontSee('Lain-lain');
});

it('rejects a below-bill payment (no installment created)', function () {
    Livewire::test(CreateInstallment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_id' => $this->loan->id,
            'schedule_id' => $this->schedule->id,
            'payment_method' => 'manual',
            'amount_paid' => '1089999',
            'payment_date' => now()->toDateString(),
        ])
        ->call('create');

    expect(Installment::where('loan_id', $this->loan->id)->exists())->toBeFalse()
        ->and($this->loan->fresh()->status)->toBe('Cair');
});
