<?php

namespace App\Filament\Pages;

use App\Exports\DepositReportExport;
use App\Filament\Pages\Concerns\LogsReportExport;
use App\Filament\Resources\SavingsDepositResource;
use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Services\DepositReportService;
use App\Support\ReportLetterhead;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanSetoranSimpanan extends Page implements HasForms
{
    use InteractsWithForms;
    use LogsReportExport;

    public const PERMISSION = 'access_laporan_setoran';

    public const EXPORT_PERMISSION = 'export_laporan_setoran';

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Laporan Simpanan';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Laporan Setoran Simpanan';

    protected static string $view = 'filament.pages.laporan-setoran-simpanan';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Filter yang sudah divalidasi & diterapkan (null = belum generate). Preview
     * membaca dari sini, bukan dari state form live, agar tabel tak ikut berubah
     * sebelum tombol "Tampilkan" ditekan.
     *
     * @var array<string, mixed>|null
     */
    public ?array $appliedFilters = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->visible(fn (): bool => auth()->user()?->can(self::EXPORT_PERMISSION) ?? false)
                ->action(fn (): BinaryFileResponse => $this->exportExcel()),
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can(self::EXPORT_PERMISSION) ?? false)
                ->action(fn (): StreamedResponse => $this->exportPdf()),
        ];
    }

    public function exportExcel(): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can(self::EXPORT_PERMISSION) ?? false, 403);

        $filters = $this->form->getState();

        $service = app(DepositReportService::class);

        $rows = $service->rows($filters);

        $this->logReportExport('setoran', 'excel', $filters, $rows->count());

        $filename = 'laporan-setoran-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new DepositReportExport($rows, $service->totals($filters)),
            $filename,
        );
    }

    public function exportPdf(): StreamedResponse
    {
        abort_unless(auth()->user()?->can(self::EXPORT_PERMISSION) ?? false, 403);

        $filters = $this->form->getState();

        $grouped = app(DepositReportService::class)->grouped($filters);

        $this->logReportExport('setoran', 'pdf', $filters, $this->countGroupedRows($grouped));

        $pdf = Pdf::loadView('reports.deposit-pdf', [
            'title' => static::$title,
            'subtitle' => $this->filterSummary($filters),
            'kop' => ReportLetterhead::make(),
            'groups' => $grouped['groups'],
            'grandTotal' => $grouped['grand_total'],
            'generatedAt' => now(),
            'savingsTypeLabel' => fn (?string $t): string => $this->savingsTypeLabel($t),
            'depositMethodLabel' => fn (?string $m): string => $this->depositMethodLabel($m),
        ]);

        $filename = 'laporan-setoran-'.now()->format('Ymd-His').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Ringkasan filter yang diterapkan, untuk sub-judul PDF (jejak audit visual).
     *
     * @param  array<string, mixed>  $filters
     */
    private function filterSummary(array $filters): string
    {
        $parts = [
            'Basis: '.(($filters['basis'] ?? '') === DepositReportService::BASIS_DEPOSIT_DATE
                ? 'Tanggal Setor'
                : 'Periode Potong Gaji'),
            'Periode: '.Carbon::parse($filters['start'])->format('d/m/Y').' – '.Carbon::parse($filters['end'])->format('d/m/Y'),
        ];

        if (! empty($filters['savings_type'])) {
            $parts[] = 'Jenis: '.collect((array) $filters['savings_type'])
                ->map(fn (string $t): string => $this->savingsTypeLabel($t))
                ->implode(', ');
        }

        if (! empty($filters['deposit_method'])) {
            $parts[] = 'Metode: '.$this->depositMethodLabel($filters['deposit_method']);
        }

        if (! empty($filters['agency_id'])) {
            $parts[] = 'OPD: '.(Agency::find($filters['agency_id'])?->agency_name ?? $filters['agency_id']);
        }

        if (! empty($filters['member_id'])) {
            $member = Member::withTrashed()->find($filters['member_id']);
            $parts[] = 'Anggota: '.($member?->full_name ?? $filters['member_id']);
        }

        return implode('  |  ', $parts);
    }

    public function mount(): void
    {
        $this->form->fill([
            'basis' => DepositReportService::BASIS_PERIOD_MONTH,
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Filter Laporan')
                    ->icon('heroicon-o-funnel')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Radio::make('basis')
                            ->label('Basis Periode')
                            ->options([
                                DepositReportService::BASIS_PERIOD_MONTH => 'Periode Potong Gaji (rekonsiliasi payroll)',
                                DepositReportService::BASIS_DEPOSIT_DATE => 'Tanggal Setor (sukarela/hari raya)',
                            ])
                            ->default(DepositReportService::BASIS_PERIOD_MONTH)
                            ->live()
                            ->required()
                            ->helperText('Basis "Periode Potong Gaji" mengecualikan setoran tanpa periode (mis. sukarela/hari raya). Untuk jenis tersebut pakai "Tanggal Setor".')
                            ->columnSpanFull(),
                        Forms\Components\DatePicker::make('start')
                            ->label('Dari Tanggal')
                            ->required()
                            ->beforeOrEqual('end'),
                        Forms\Components\DatePicker::make('end')
                            ->label('Sampai Tanggal')
                            ->required()
                            ->afterOrEqual('start')
                            ->rule(static function (Get $get): Closure {
                                return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $start = $get('start');

                                    if (blank($start) || blank($value)) {
                                        return;
                                    }

                                    if (Carbon::parse($start)->diffInDays(Carbon::parse($value)) > 366) {
                                        $fail('Rentang maksimum 1 tahun.');
                                    }
                                };
                            }),
                        Forms\Components\Select::make('savings_type')
                            ->label('Jenis Simpanan')
                            ->multiple()
                            ->options(SavingsDepositResource::SAVINGS_TYPES)
                            ->placeholder('Semua jenis'),
                        Forms\Components\Select::make('deposit_method')
                            ->label('Metode Setor')
                            ->options(SavingsDepositResource::DEPOSIT_METHODS)
                            ->placeholder('Semua metode'),
                        Forms\Components\Select::make('agency_id')
                            ->label('OPD / Instansi')
                            ->options(fn (): array => Agency::query()->orderBy('agency_name')->pluck('agency_name', 'id')->all())
                            ->searchable()
                            ->placeholder('Semua OPD'),
                        Forms\Components\Select::make('member_id')
                            ->label('Anggota')
                            ->searchable()
                            ->placeholder('Semua anggota')
                            ->getSearchResultsUsing(fn (string $search): array => Member::query()
                                ->withTrashed()
                                ->where(fn ($q) => $q->where('full_name', 'like', "%{$search}%")
                                    ->orWhere('member_number', 'like', "%{$search}%"))
                                ->orderBy('full_name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Member $m): array => [$m->id => "{$m->member_number} — {$m->full_name}"])
                                ->all())
                            ->getOptionLabelUsing(function ($value): ?string {
                                $member = Member::withTrashed()->find($value);

                                return $member ? "{$member->member_number} — {$member->full_name}" : null;
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function generate(): void
    {
        $this->appliedFilters = $this->form->getState();
    }

    /**
     * Baris hasil (kosong bila belum generate). Dihitung on-demand dari service.
     *
     * @return Collection<int, SavingsDeposit>
     */
    public function getRowsProperty(): Collection
    {
        if ($this->appliedFilters === null) {
            return collect();
        }

        return app(DepositReportService::class)->rows($this->appliedFilters);
    }

    public function getTotalProperty(): string
    {
        if ($this->appliedFilters === null) {
            return '0';
        }

        return app(DepositReportService::class)->totals($this->appliedFilters);
    }

    public function savingsTypeLabel(?string $type): string
    {
        return SavingsDepositResource::SAVINGS_TYPES[$type] ?? (string) $type;
    }

    public function depositMethodLabel(?string $method): string
    {
        return SavingsDepositResource::DEPOSIT_METHODS[$method] ?? (string) $method;
    }
}
