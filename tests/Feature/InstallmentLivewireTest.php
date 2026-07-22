<?php

use App\Livewire\Loan\Installment\InstallmentDetail;
use App\Livewire\Loan\Installment\InstallmentForm;
use App\Livewire\Loan\Installment\Installments;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    asSuperAdmin();
    $this->member = Member::factory()->create();
    $this->loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'status' => 'Cair',
        'principal_amount' => 12000000,
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

it('renders a settlement installment with the Pelunasan Dipercepat badge', function () {
    $settlement = Installment::factory()->create([
        'loan_id' => $this->loan->id,
        'is_settlement' => true,
        'installment_seq' => null,
        'schedule_id' => null,
        'amount_paid' => 12078000,
    ]);

    Livewire::test(InstallmentDetail::class, ['installment' => $settlement])
        ->assertSee('Pelunasan Dipercepat')
        ->assertDontSee('Angsuran ke-');
});

it('shows the readonly bill detail including Total Tagihan once a loan/schedule is picked', function () {
    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->assertSee('Total Tagihan')
        ->assertSee('Tab. Berjangka')
        ->assertSee('1.090.000');
});

it('accepts a PDF as bukti when recording payment', function () {
    Storage::fake(config('media-library.disk_name'));

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('bukti', UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))
        ->call('pay')
        ->assertHasNoErrors();

    expect(Installment::where('loan_id', $this->loan->id)->firstOrFail()->hasMedia('bukti'))->toBeTrue();
});

it('rejects a disallowed bukti type when recording payment', function () {
    Storage::fake(config('media-library.disk_name'));

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('bukti', UploadedFile::fake()->create('virus.exe', 100, 'application/octet-stream'))
        ->call('pay')
        ->assertHasErrors('bukti');

    expect(Installment::where('loan_id', $this->loan->id)->exists())->toBeFalse();
});

it('renders the image bukti inline with a full-size link on the detail page', function () {
    Storage::fake(config('media-library.disk_name'));
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);
    $inst->addMedia(UploadedFile::fake()->image('bukti.jpg'))->toMediaCollection('bukti');

    Livewire::test(InstallmentDetail::class, ['installment' => $inst])
        ->assertSee('Bukti Pembayaran')
        ->assertSee('buka ukuran penuh')
        ->assertSee('<img', escape: false)
        ->assertDontSee('Buka bukti (PDF)');
});

it('renders a PDF bukti as an open-in-new-tab button on the detail page', function () {
    Storage::fake(config('media-library.disk_name'));
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);
    $inst->addMedia(UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))->toMediaCollection('bukti');

    Livewire::test(InstallmentDetail::class, ['installment' => $inst])
        ->assertSee('Buka bukti (PDF) di tab baru')
        ->assertDontSee('buka ukuran penuh');
});

it('flags rows that have a bukti in the listing', function () {
    Storage::fake(config('media-library.disk_name'));
    $withBukti = Installment::factory()->create(['loan_id' => $this->loan->id]);
    $withBukti->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection('bukti');
    Installment::factory()->create(['loan_id' => $this->loan->id]);

    Livewire::test(Installments::class)
        ->assertSee('Ada bukti');
});
