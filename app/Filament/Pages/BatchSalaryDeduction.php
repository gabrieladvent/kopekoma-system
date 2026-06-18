<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\MoneyInput;
use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Services\BatchSalaryDeductionService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Setoran batch potong gaji per OPD (item 3a-1, D5). Pilih OPD + periode →
 * tabel anggota aktif dengan nominal prefill snapshot golongan → proses.
 * Engine ada di {@see BatchSalaryDeductionService}; halaman ini murni UI.
 */
class BatchSalaryDeduction extends Page implements HasForms
{
    use InteractsWithForms;

    public const PERMISSION = 'access_batch_salary_deduction';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Simpanan';

    protected static ?string $navigationLabel = 'Batch Potong Gaji';

    protected static ?string $title = 'Batch Potong Gaji per OPD';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.batch-salary-deduction';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Custom Page tak punya auto-policy Shield → enforce permission eksplisit
     * (security #C): sembunyikan dari nav + tolak akses bila tak berhak.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }

    public const EXPORT_PERMISSION = 'export_savings_recap';

    /**
     * Rekap (3b): export CSV setoran wajib potong-gaji OPD+periode. PII finansial
     * → gate Pengurus+ (D7), dan aktivitas export **ter-log**.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportRecap')
                ->label('Export Rekap (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can(self::EXPORT_PERMISSION) ?? false)
                ->action(fn (): StreamedResponse => $this->exportRecap()),
        ];
    }

    public function exportRecap(): StreamedResponse
    {
        abort_unless(auth()->user()?->can(self::EXPORT_PERMISSION) ?? false, 403);

        $state = $this->form->getState();
        $agency = Agency::findOrFail($state['agency_id']);
        $period = Carbon::parse($state['period_month'])->startOfMonth()->toDateString();

        $deposits = SavingsDeposit::query()
            ->where('savings_type', BatchSalaryDeductionService::SAVINGS_TYPE)
            ->where('deposit_method', BatchSalaryDeductionService::METHOD)
            ->whereDate('period_month', $period)
            ->where('is_reversal', false)
            ->whereHas('member', fn ($q) => $q->where('agency_id', $agency->getKey()))
            ->with('member')
            ->orderBy('transaction_number')
            ->get();

        // Export ter-log (security #E): aktor, OPD, periode, jumlah baris.
        activity()
            ->causedBy(auth()->id())
            ->event('export')
            ->withProperties([
                'agency_id' => $agency->getKey(),
                'period_month' => $period,
                'rows' => $deposits->count(),
            ])
            ->log("Export rekap potong gaji OPD {$agency->agency_name} periode {$period}: {$deposits->count()} baris");

        $filename = 'rekap-potong-gaji-'.$agency->agency_code.'-'.Carbon::parse($period)->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($deposits): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['No. Transaksi', 'No. Anggota', 'Nama', 'Nominal', 'Tanggal Setor', 'Periode']);

            foreach ($deposits as $d) {
                fputcsv($out, [
                    $d->transaction_number,
                    $d->member?->member_number,
                    $d->member?->full_name,
                    $d->amount,
                    optional($d->deposit_date)->format('Y-m-d'),
                    optional($d->period_month)->format('Y-m'),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function mount(): void
    {
        $this->form->fill([
            'period_month' => now()->startOfMonth()->toDateString(),
            'rows' => [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pilih OPD & Periode')
                    ->icon('heroicon-o-building-office-2')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('agency_id')
                            ->label('OPD / Instansi')
                            ->options(fn (): array => Agency::query()->orderBy('agency_name')->pluck('agency_name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('rows', $this->buildRows($get('agency_id')))),
                        Forms\Components\DatePicker::make('period_month')
                            ->label('Periode')
                            ->displayFormat('F Y')
                            ->required()
                            ->live()
                            ->helperText('Bulan potong gaji. Anggota yang sudah disetor periode ini akan dilewati otomatis.'),
                    ]),
                Forms\Components\Section::make('Anggota Aktif')
                    ->icon('heroicon-o-user-group')
                    ->description('Centang anggota yang ikut potong gaji, sesuaikan nominal bila perlu.')
                    ->schema([
                        Forms\Components\Repeater::make('rows')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(3)
                            ->schema([
                                Forms\Components\Hidden::make('member_id'),
                                Forms\Components\TextInput::make('member_label')
                                    ->label('Anggota')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\Toggle::make('include')
                                    ->label('Ikut')
                                    ->default(true)
                                    ->inline(false),
                                MoneyInput::make('amount')
                                    ->label('Nominal')
                                    ->required()
                                    ->minValue(1),
                            ])
                            ->visible(fn (Get $get): bool => filled($get('agency_id'))),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Baris anggota aktif OPD terpilih + prefill nominal wajib (snapshot golongan).
     *
     * @return list<array{member_id:string, member_label:string, include:bool, amount:?string}>
     */
    protected function buildRows(?string $agencyId): array
    {
        if (blank($agencyId)) {
            return [];
        }

        return Member::query()
            ->where('agency_id', $agencyId)
            ->where('status', 'Aktif')
            ->orderBy('full_name')
            ->get(['id', 'member_number', 'full_name', 'mandatory_savings_amount'])
            ->map(fn (Member $m): array => [
                'member_id' => $m->id,
                'member_label' => "{$m->member_number} — {$m->full_name}",
                'include' => true,
                'amount' => $m->mandatory_savings_amount === null ? null : (string) $m->mandatory_savings_amount,
            ])
            ->all();
    }

    public function process(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $agency = Agency::findOrFail($state['agency_id']);

        $rows = collect($state['rows'] ?? [])
            ->filter(fn (array $r): bool => (bool) ($r['include'] ?? false))
            ->map(fn (array $r): array => ['member_id' => $r['member_id'], 'amount' => $r['amount']])
            ->values()
            ->all();

        if ($rows === []) {
            Notification::make()
                ->warning()
                ->title('Tidak ada anggota dipilih')
                ->body('Centang minimal satu anggota untuk diproses.')
                ->send();

            return;
        }

        try {
            $result = app(BatchSalaryDeductionService::class)->run(
                $agency,
                $state['period_month'],
                $rows,
            );
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->danger()
                ->title('Batch gagal')
                ->body($e->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Batch potong gaji selesai')
            ->body("{$result['created']} setoran dibuat, {$result['skipped']} dilewati (sudah disetor periode ini).")
            ->send();

        // Muat ulang daftar (state form) — sekadar refresh prefill nominal.
        $this->data['rows'] = $this->buildRows($state['agency_id']);
    }

    public function getPeriodLabel(): string
    {
        $period = $this->data['period_month'] ?? null;

        return $period ? Carbon::parse($period)->translatedFormat('F Y') : '-';
    }
}
