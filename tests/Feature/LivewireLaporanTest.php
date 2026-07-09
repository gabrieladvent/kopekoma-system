<?php

use App\Exports\DepositReportExport;
use App\Livewire\Reports\LaporanAngsuranPinjaman;
use App\Livewire\Reports\LaporanSetoranSimpanan;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

// ── Akses & render (petugas boleh lihat) ─────────────────────────────────

it('renders the deposit report page for petugas', function () {
    asPetugas();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->assertOk()
        ->assertSee('Laporan Setoran Simpanan');
});

it('renders the installment report page for petugas', function () {
    asPetugas();

    Livewire::test(LaporanAngsuranPinjaman::class)
        ->assertOk()
        ->assertSee('Laporan Angsuran Pinjaman');
});

// ── Export button visibility (pengurus-only) ─────────────────────────────

it('shows export buttons to pengurus but hides them from petugas', function () {
    asPengurus();
    Livewire::test(LaporanSetoranSimpanan::class)->assertSeeHtml('wire:click="exportExcel"');

    asPetugas();
    Livewire::test(LaporanSetoranSimpanan::class)->assertDontSeeHtml('wire:click="exportExcel"');
});

// ── Preview (generate) menampilkan net of reversals ──────────────────────

it('previews net-of-reversal rows after generate', function () {
    asPetugas();
    $member = Member::factory()->create();
    $original = SavingsDeposit::factory()->create([
        'member_id' => $member->id, 'savings_type' => 'wajib', 'amount' => 100000,
        'period_month' => '2026-06-01', 'deposit_method' => 'potong_gaji', 'is_reversal' => false,
    ]);
    SavingsDeposit::factory()->create([
        'member_id' => $member->id, 'savings_type' => 'wajib', 'amount' => 30000,
        'period_month' => '2026-06-01', 'deposit_method' => 'potong_gaji',
        'is_reversal' => true, 'reversal_of_id' => $original->id,
    ]);

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('basis', 'period_month')
        ->set('start', '2026-06-01')
        ->set('end', '2026-06-30')
        ->call('generate')
        ->assertOk()
        // 2 baris (asli + reversal) tampil; grand total net = 70.000.
        ->assertSee('70.000');
});

it('rejects a range longer than one year', function () {
    asPetugas();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('start', '2026-01-01')
        ->set('end', '2027-06-30')
        ->call('generate')
        ->assertHasErrors('end');
});

// ── Gating export server-side ────────────────────────────────────────────

it('forbids the deposit excel export for petugas (403)', function () {
    asPetugas();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('start', '2026-06-01')
        ->set('end', '2026-06-30')
        ->call('exportExcel')
        ->assertStatus(403);
});

it('forbids the deposit pdf export for petugas (403)', function () {
    asPetugas();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('start', '2026-06-01')
        ->set('end', '2026-06-30')
        ->call('exportPdf')
        ->assertStatus(403);
});

// ── End-to-end pengurus (permission asli) ────────────────────────────────

it('lets pengurus download the deposit excel and logs it', function () {
    Excel::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00'));
    $actor = asPengurus();
    $member = Member::factory()->create();
    SavingsDeposit::factory()->create([
        'member_id' => $member->id, 'savings_type' => 'wajib', 'amount' => 100000,
        'period_month' => '2026-06-01', 'deposit_method' => 'potong_gaji', 'is_reversal' => false,
    ]);

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('basis', 'period_month')
        ->set('start', '2026-06-01')
        ->set('end', '2026-06-30')
        ->call('exportExcel');

    Excel::assertDownloaded(
        'laporan-setoran-20260709-100000.xlsx',
        fn ($export): bool => $export instanceof DepositReportExport,
    );

    $log = Activity::where('event', 'export')->latest('id')->first();
    expect($log->causer_id)->toBe($actor->id)
        ->and($log->properties['report'])->toBe('setoran')
        ->and($log->properties['format'])->toBe('excel')
        ->and($log->properties['agency_id'])->toBe('ALL_OPD')
        ->and($log->properties['member_id'])->toBe('ALL_MEMBER');

    Carbon::setTestNow();
});

it('lets pengurus export the installment pdf and logs report=angsuran', function () {
    $actor = asPengurus();
    $loan = Loan::factory()->create();
    Installment::factory()->create([
        'loan_id' => $loan->id, 'payment_date' => '2026-06-05', 'amount_paid' => 500000, 'is_reversal' => false,
    ]);

    Livewire::test(LaporanAngsuranPinjaman::class)
        ->set('start', '2026-06-01')
        ->set('end', '2026-06-30')
        ->call('exportPdf')
        ->assertOk();

    $log = Activity::where('event', 'export')->latest('id')->first();
    expect($log->causer_id)->toBe($actor->id)
        ->and($log->properties['report'])->toBe('angsuran')
        ->and($log->properties['format'])->toBe('pdf')
        ->and($log->properties->has('basis'))->toBeFalse();
});

// ── Full-page render (layout + collapsible nav compiles) ─────────────────

it('renders the full page with the Laporan nav group for petugas', function () {
    $user = asPetugas();

    $this->actingAs($user)->get(route('reports.setoran'))
        ->assertOk()
        ->assertSee('Laporan Setoran Simpanan')
        // Grup nav "Laporan" + toggle collapsible ter-render.
        ->assertSee('nav:laporan');
});

it('blocks a user without report access from the page (403)', function () {
    // super_admin bypass Gate::before; pakai user tanpa role laporan.
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('reports.setoran'))->assertForbidden();
});

// ── Member picker ────────────────────────────────────────────────────────

it('selects and clears a member in the picker', function () {
    asPetugas();
    $member = Member::factory()->create(['full_name' => 'Budi Santoso', 'member_number' => 'A-001']);

    Livewire::test(LaporanSetoranSimpanan::class)
        ->call('selectMember', $member->id)
        ->assertSet('member_id', $member->id)
        ->assertSet('memberLabel', 'A-001 — Budi Santoso')
        ->call('clearMember')
        ->assertSet('member_id', '');
});
