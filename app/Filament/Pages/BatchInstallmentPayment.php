<?php

namespace App\Filament\Pages;

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\InstallmentResource;
use App\Models\Agency;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Services\BatchInstallmentPaymentService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class BatchInstallmentPayment extends Page implements HasForms
{
    use InteractsWithForms;

    public const PERMISSION = 'access_batch_salary_deduction';

    private const BUKTI_DIR = 'tmp/installment-bukti';

    protected static ?string $title = 'Batch Potong Gaji Angsuran per OPD';

    protected static string $view = 'filament.pages.batch-installment-payment';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'period_month' => now()->startOfMonth()->toDateString(),
            'payment_date' => now()->toDateString(),
            'rows' => [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pilih OPD & Periode')
                    ->icon('heroicon-o-building-office-2')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('agency_id')
                            ->label('OPD / Instansi')
                            ->options(fn (): array => Agency::query()->orderBy('agency_name')->pluck('agency_name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('rows', $this->buildRows($get('agency_id')))),

                        Forms\Components\DatePicker::make('payment_date')
                            ->label('Tanggal Bayar')
                            ->default(now())
                            ->required(),

                        Forms\Components\DatePicker::make('period_month')
                            ->label('Periode Potong Gaji')
                            ->displayFormat('F Y')
                            ->required()
                            ->helperText('Hanya untuk pelabelan rekap/audit batch.'),
                    ]),

                Forms\Components\Section::make('Anggota dengan Pinjaman Aktif')
                    ->icon('heroicon-o-user-group')
                    ->description('Aktifkan "Ikut" untuk anggota yang dipotong, lalu sesuaikan nominal tiap pinjaman bila membayar lebih dari tagihan (kelebihan dikreditkan ke Simpanan Sukarela anggota).')
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
                                    ->dehydrated(false)
                                    ->columnSpan(2),

                                Forms\Components\Toggle::make('include')
                                    ->label('Ikut')
                                    ->default(true)
                                    ->inline(false)
                                    ->live(),

                                Forms\Components\Repeater::make('lines')
                                    ->hiddenLabel()
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->visible(fn (Get $get): bool => (bool) $get('include'))
                                    ->schema([
                                        Forms\Components\Hidden::make('schedule_id'),
                                        Forms\Components\Hidden::make('loan_id'),
                                        Forms\Components\Hidden::make('total_due'),
                                        Forms\Components\TextInput::make('loan_label')
                                            ->label('Angsuran')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('loan_total_label')
                                            ->label('Pinjaman')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->columnSpanFull(),
                                        Forms\Components\Toggle::make('include')
                                            ->label('Ikut')
                                            ->inline(false)
                                            ->live(),
                                        MoneyInput::make('amount')
                                            ->label('Nominal')
                                            ->dehydrated()
                                            ->live(onBlur: true)
                                            ->required(fn (Get $get): bool => (bool) $get('include'))
                                            ->minValue(fn (Get $get): int => (int) $get('total_due'))
                                            ->helperText('Tidak boleh kurang dari tagihan.'),
                                        Forms\Components\FileUpload::make('bukti')
                                            ->label('Bukti Pembayaran')
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                            ->openable()
                                            ->downloadable()
                                            ->disk(config('media-library.disk_name'))
                                            ->directory(self::BUKTI_DIR)
                                            ->visibility('public')
                                            ->columnSpanFull()
                                            ->helperText('Opsional. Gambar atau PDF — slip/foto/kuitansi pendukung untuk anggota ini.'),
                                    ]),
                            ])
                            ->visible(fn (Get $get): bool => filled($get('agency_id'))),
                        Forms\Components\Placeholder::make('grand_total')
                            ->label('Total Potong Gaji (yang diikutkan)')
                            ->visible(fn (Get $get): bool => filled($get('agency_id')))
                            ->content(fn (Get $get): HtmlString => new HtmlString(
                                '<span class="text-lg font-bold text-primary-600 dark:text-primary-400">Rp '
                                .number_format($this->grandTotal($get('rows') ?? []), 0, ',', '.')
                                .'</span>'
                            )),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Total nominal seluruh baris yang diikutkan (anggota Ikut & pinjaman Ikut).
     * Konsisten dengan filter di {@see process()} agar angka di layar = yang diproses.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function grandTotal(array $rows): float
    {
        return collect($rows)
            ->filter(fn (array $row): bool => (bool) ($row['include'] ?? false))
            ->flatMap(fn (array $row): array => $row['lines'] ?? [])
            ->filter(fn (array $line): bool => (bool) ($line['include'] ?? false))
            ->sum(fn (array $line): float => (float) ($line['amount'] ?? 0));
    }

    /**
     * Baris per anggota OPD terpilih yang punya pinjaman aktif. Tiap anggota
     * membawa daftar pinjaman aktifnya (`lines`); tiap pinjaman = jadwal terlama
     * yang belum bayar (FIFO), prefill nominal = tagihan bulan itu.
     *
     * @return list<array{member_id:string, member_label:string, include:bool, lines:list<array<string, mixed>>}>
     */
    protected function buildRows(?string $agencyId): array
    {
        if (blank($agencyId)) {
            return [];
        }

        return Loan::query()
            ->where('status', LoanStatus::Cair)
            ->whereHas('member', fn ($query) => $query->where('agency_id', $agencyId)->where('status', 'Aktif'))
            ->with('member')
            ->get()
            ->groupBy('member_id')
            ->map(function (Collection $loans): array {
                $member = $loans->first()->member;

                $lines = $loans
                    ->map(fn (Loan $loan): ?array => $this->buildLoanLine($loan))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'member_id' => $member->id,
                    'member_label' => "{$member->member_number} — {$member->full_name}",
                    'include' => true,
                    'lines' => $lines,
                ];
            })
            ->filter(fn (array $row): bool => $row['lines'] !== [])
            ->sortBy('member_label')
            ->values()
            ->all();
    }

    /**
     * Satu baris pinjaman: jadwal terlama belum bayar (FIFO, konsisten
     * `InstallmentResource::unpaidScheduleOptions`). Null bila tak ada jadwal
     * belum bayar (anomali — pinjaman Cair semestinya punya sisa jadwal).
     *
     * @return array{loan_id:string, schedule_id:string, total_due:string, loan_label:string, loan_total_label:string, include:bool, amount:string, bukti:null}|null
     */
    protected function buildLoanLine(Loan $loan): ?array
    {
        $schedule = InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->orderBy('installment_seq')
            ->first();

        if ($schedule === null) {
            return null;
        }

        $bill = (string) (int) round((float) $schedule->total_due);

        return [
            'loan_id' => $loan->id,
            'schedule_id' => $schedule->id,
            'total_due' => $bill,
            'loan_label' => sprintf(
                '%s — angsuran #%d — jatuh tempo %s — tagihan Rp %s',
                $loan->loan_number,
                $schedule->installment_seq,
                $schedule->due_date?->format('d/m/Y'),
                number_format((float) $schedule->total_due, 0, ',', '.'),
            ),
            'loan_total_label' => sprintf(
                'Total pinjaman Rp %s — sisa pokok Rp %s',
                number_format((float) $loan->principal_amount, 0, ',', '.'),
                number_format((float) $loan->remainingPrincipal(), 0, ',', '.'),
            ),
            'include' => true,
            'amount' => $bill,
            'bukti' => null,
        ];
    }

    public function process(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $agency = Agency::findOrFail($state['agency_id']);

        $paymentDate = $state['payment_date'] ?? now()->toDateString();

        $rows = collect($state['rows'] ?? [])
            ->filter(fn (array $row): bool => (bool) ($row['include'] ?? false))
            ->flatMap(fn (array $row): array => collect($row['lines'] ?? [])
                ->filter(fn (array $line): bool => (bool) ($line['include'] ?? false))
                ->map(fn (array $line): array => [
                    'schedule_id' => (string) $line['schedule_id'],
                    'amount_paid' => $line['amount'] ?? '0',
                    'payment_date' => $paymentDate,
                    'bukti_path' => is_string($line['bukti'] ?? null) ? $line['bukti'] : null,
                    'bukti_disk' => config('media-library.disk_name'),
                ])
                ->values()
                ->all())
            ->values()
            ->all();

        if ($rows === []) {
            Notification::make()
                ->warning()
                ->title('Tidak ada angsuran dipilih')
                ->body('Aktifkan minimal satu anggota dan satu pinjaman untuk dibayar.')
                ->send();

            return;
        }

        try {
            $result = app(BatchInstallmentPaymentService::class)->run(
                $agency,
                $state['period_month'],
                $rows,
                auth()->id(),
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
            ->title('Batch potong gaji angsuran selesai')
            ->body("{$result['created']} angsuran dicatat, {$result['skipped']} dilewati (sudah terbayar / pinjaman lunas).")
            ->send();

        $this->data['rows'] = $this->buildRows($state['agency_id']);
    }
}
