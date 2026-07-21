<?php

use App\Exports\DepositReportExport;
use App\Exports\InstallmentReportExport;
use App\Filament\Pages\LaporanAngsuranPinjaman;
use App\Filament\Pages\LaporanSetoranSimpanan;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Services\DepositReportService;
use App\Services\InstallmentReportService;
use App\Settings\CooperativeSettings;
use App\Support\ReportLetterhead;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

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

// ── PDF export (item 3b) ─────────────────────────────────────────────────

it('shows the PDF export button to pengurus but hides it from petugas', function () {
    asPengurus();
    Livewire::test(LaporanSetoranSimpanan::class)->assertActionVisible('exportPdf');

    asPetugas();
    Livewire::test(LaporanSetoranSimpanan::class)->assertActionHidden('exportPdf');
});

it('forbids the PDF export server-side when the user lacks the export permission', function () {
    asPetugas();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.start', '2026-06-01')
        ->set('data.end', '2026-06-30')
        ->call('exportPdf')
        ->assertStatus(403);
});

it('renders a valid deposit PDF with the letterhead grouped by OPD', function () {
    asSuperAdmin();
    $coop = app(CooperativeSettings::class);
    $coop->cooperative_address = 'Jl. Merdeka No. 1';
    $coop->cooperative_city = 'Denpasar';
    $coop->signatory_name = 'Budi Santoso';
    $coop->signatory_position = 'Ketua';
    $coop->save();

    $agency = Agency::factory()->create(['agency_name' => 'Dinas Contoh']);
    $member = Member::factory()->create(['agency_id' => $agency->id]);
    SavingsDeposit::factory()->create([
        'member_id' => $member->id, 'savings_type' => 'wajib', 'amount' => 100000,
        'period_month' => '2026-06-01', 'deposit_method' => 'potong_gaji', 'is_reversal' => false,
    ]);

    $filters = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];
    $grouped = app(DepositReportService::class)->grouped($filters);

    $bytes = Pdf::loadView('reports.deposit-pdf', [
        'title' => 'Laporan Setoran Simpanan',
        'subtitle' => 'Periode: 01/06/2026 – 30/06/2026',
        'kop' => ReportLetterhead::make(),
        'groups' => $grouped['groups'],
        'grandTotal' => $grouped['grand_total'],
        'generatedAt' => now(),
        'savingsTypeLabel' => fn (?string $t): string => (string) $t,
        'depositMethodLabel' => fn (?string $m): string => (string) $m,
    ])->output();

    // Valid PDF terbentuk → blade + partial kop/ttd compile tanpa error.
    expect($bytes)->toStartWith('%PDF');
});

// ── Audit log export (item 3c) ───────────────────────────────────────────

it('logs the excel export with actor, format, sentinels and row count', function () {
    Excel::fake();
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

    $log = Activity::where('event', 'export')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->not->toBeNull()
        ->and($log->properties['report'])->toBe('setoran')
        ->and($log->properties['format'])->toBe('excel')
        // Tak difilter → sentinel eksplisit (seluruh koperasi).
        ->and($log->properties['agency_id'])->toBe('ALL_OPD')
        ->and($log->properties['member_id'])->toBe('ALL_MEMBER')
        ->and($log->properties['savings_type'])->toBe('ALL')
        ->and($log->properties['deposit_method'])->toBe('ALL')
        ->and($log->properties['rows'])->toBe(1);
});

it('logs the pdf export with concrete filter ids and never leaks PII', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $member = Member::factory()->create([
        'agency_id' => $agency->id, 'full_name' => 'Budi Santoso', 'nik' => '3399887766554433',
    ]);
    SavingsDeposit::factory()->create([
        'member_id' => $member->id, 'savings_type' => 'wajib', 'amount' => 100000,
        'period_month' => '2026-06-01', 'deposit_method' => 'potong_gaji', 'is_reversal' => false,
    ]);

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.basis', 'period_month')
        ->set('data.start', '2026-06-01')
        ->set('data.end', '2026-06-30')
        ->set('data.agency_id', $agency->id)
        ->set('data.member_id', $member->id)
        ->call('exportPdf');

    $log = Activity::where('event', 'export')->latest('id')->first();
    $encoded = json_encode($log->properties->toArray());

    expect($log->properties['format'])->toBe('pdf')
        ->and($log->properties['agency_id'])->toBe($agency->id)
        ->and($log->properties['member_id'])->toBe($member->id)
        ->and($log->properties['rows'])->toBe(1)
        // Hanya id + hitungan — tak ada nama/NIK di properties.
        ->and($encoded)->not->toContain('Budi Santoso')
        ->and($encoded)->not->toContain('3399887766554433');
});

// ── End-to-end lewat permission pengurus asli (bukan bypass super_admin) ──

it('lets a real pengurus export excel end-to-end and logs it under their name', function () {
    Excel::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-08 10:00:00'));
    $actor = asPengurus();
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

    $log = Activity::where('event', 'export')->latest('id')->first();
    // Permission yang di-grant (bukan Gate::before super_admin) yang mengizinkan → causer = pengurus.
    expect($log->causer_id)->toBe($actor->id)
        ->and($log->properties['format'])->toBe('excel');

    Carbon::setTestNow();
});

it('lets a real pengurus export pdf end-to-end and logs it', function () {
    $actor = asPengurus();
    $member = Member::factory()->create();
    SavingsDeposit::factory()->create([
        'member_id' => $member->id, 'savings_type' => 'wajib', 'amount' => 100000,
        'period_month' => '2026-06-01', 'deposit_method' => 'potong_gaji', 'is_reversal' => false,
    ]);

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.basis', 'period_month')
        ->set('data.start', '2026-06-01')
        ->set('data.end', '2026-06-30')
        ->call('exportPdf')
        ->assertOk();

    $log = Activity::where('event', 'export')->latest('id')->first();
    expect($log->causer_id)->toBe($actor->id)
        ->and($log->properties['format'])->toBe('pdf');
});

it('logs the installment export as report=angsuran without deposit-only keys', function () {
    Excel::fake();
    asSuperAdmin();
    $loan = Loan::factory()->create();
    Installment::factory()->create([
        'loan_id' => $loan->id, 'payment_date' => '2026-06-05', 'amount_paid' => 500000, 'is_reversal' => false,
    ]);

    Livewire::test(LaporanAngsuranPinjaman::class)
        ->set('data.start', '2026-06-01')
        ->set('data.end', '2026-06-30')
        ->call('exportExcel');

    $log = Activity::where('event', 'export')->latest('id')->first();

    expect($log->properties['report'])->toBe('angsuran')
        ->and($log->properties['format'])->toBe('excel')
        ->and($log->properties['agency_id'])->toBe('ALL_OPD')
        // Angsuran tak punya basis/savings_type/deposit_method.
        ->and($log->properties->has('basis'))->toBeFalse()
        ->and($log->properties->has('savings_type'))->toBeFalse();
});
