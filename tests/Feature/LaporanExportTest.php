<?php

use App\Exports\DepositReportExport;
use App\Exports\InstallmentReportExport;
use App\Filament\Pages\LaporanSetoranSimpanan;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Services\DepositReportService;
use App\Services\InstallmentReportService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

// ── Kolom whitelist (security review): PII berat TIDAK boleh ikut ─────────

it('exports deposit rows with whitelisted columns only — no NIK/NIP/rekening/alamat', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create(['agency_name' => 'Dinas Contoh']);
    $member = Member::factory()->create([
        'agency_id' => $agency->id,
        'full_name' => 'Budi Santoso',
        'member_number' => 'A-001',
        'nik' => '3399887766554433',
        'address' => 'Jl. Rahasia No. 9',
    ]);
    SavingsDeposit::factory()->create([
        'member_id' => $member->id,
        'savings_type' => 'wajib',
        'amount' => 100000,
        'period_month' => '2026-06-01',
        'deposit_date' => '2026-06-05',
        'deposit_method' => 'potong_gaji',
        'is_reversal' => false,
    ]);

    $filters = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];
    $service = app(DepositReportService::class);
    $export = new DepositReportExport($service->rows($filters), $service->totals($filters));

    // Header hanya kolom aman.
    expect($export->headings())->not->toContain('nik')
        ->and($export->headings())->not->toContain('nip')
        ->and($export->headings())->toContain('No. Anggota')
        ->and($export->headings())->toContain('Nama');

    $mapped = $export->map($service->rows($filters)->first());
    $flat = implode('|', array_map('strval', $mapped));

    // PII berat tak muncul di baris data; identitas minimum tetap ada.
    expect($flat)->not->toContain('3399887766554433')
        ->and($flat)->not->toContain('Jl. Rahasia')
        ->and($flat)->toContain('A-001')
        ->and($flat)->toContain('Budi Santoso')
        ->and($flat)->toContain('Dinas Contoh');
});

it('appends a net grand-total row at the end of the deposit export', function () {
    asSuperAdmin();
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

    $filters = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];
    $service = app(DepositReportService::class);
    $export = new DepositReportExport($service->rows($filters), $service->totals($filters));

    // collection = rows + 1 baris total.
    $collection = $export->collection();
    expect($collection)->toHaveCount(3); // 2 transaksi + 1 total

    $totalRow = $export->map($collection->last());
    expect($totalRow)->toContain('TOTAL (net)')
        // net = 100000 − 30000 = 70000.00
        ->and($totalRow)->toContain('70000.00');
});

it('exports installment rows with whitelisted columns and a net total row', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create(['agency_name' => 'Dinas Contoh']);
    $member = Member::factory()->create(['agency_id' => $agency->id, 'full_name' => 'Budi Santoso', 'nik' => '3399887766554433']);
    $loan = Loan::factory()->create(['member_id' => $member->id]);
    Installment::factory()->create([
        'loan_id' => $loan->id, 'payment_date' => '2026-06-05', 'amount_paid' => 500000, 'is_reversal' => false,
    ]);

    $filters = ['start' => '2026-06-01', 'end' => '2026-06-30'];
    $service = app(InstallmentReportService::class);
    $export = new InstallmentReportExport($service->rows($filters), $service->totals($filters));

    $mapped = $export->map($service->rows($filters)->first());
    $flat = implode('|', array_map('strval', $mapped));

    expect($export->headings())->not->toContain('nik')
        ->and($flat)->not->toContain('3399887766554433')
        ->and($flat)->toContain('Budi Santoso')
        ->and($flat)->toContain('Dinas Contoh');

    $totalRow = $export->map($export->collection()->last());
    expect($totalRow)->toContain('TOTAL (net)')
        ->and($totalRow)->toContain('500000.00');
});

// ── Gating export (D7 security #E): pengurus-only ────────────────────────

it('shows the export button to pengurus but hides it from petugas', function () {
    asPengurus();
    Livewire::test(LaporanSetoranSimpanan::class)->assertActionVisible('exportExcel');

    asPetugas();
    Livewire::test(LaporanSetoranSimpanan::class)->assertActionHidden('exportExcel');
});

it('downloads the deposit export for an authorized user', function () {
    Excel::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-08 10:00:00'));
    asSuperAdmin();
    $member = Member::factory()->create();
    SavingsDeposit::factory()->create([
        'member_id' => $member->id, 'savings_type' => 'wajib', 'amount' => 100000,
        'period_month' => '2026-06-01', 'deposit_method' => 'potong_gaji', 'is_reversal' => false,
    ]);

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.basis', 'period_month')
        ->set('data.start', '2026-06-01')
        ->set('data.end', '2026-06-30')
        ->call('exportExcel');

    Excel::assertDownloaded(
        'laporan-setoran-20260708-100000.xlsx',
        fn ($export): bool => $export instanceof DepositReportExport,
    );

    Carbon::setTestNow();
});

it('forbids the export server-side when the user lacks the export permission', function () {
    asPetugas();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.start', '2026-06-01')
        ->set('data.end', '2026-06-30')
        ->call('exportExcel')
        ->assertStatus(403);
});
