<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\SavingsDepositResource;
use App\Models\Agency;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Services\BatchSalaryDeductionService;
use App\Settings\CooperativeSettings;
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

class BatchSalaryDeduction extends Page implements HasForms
{
    use InteractsWithForms;

    public const PERMISSION = 'access_batch_salary_deduction';

    public const BATCH_SAVINGS_TYPES = ['wajib', 'pokok', 'wajib_belanja'];

    protected static ?string $title = 'Batch Potong Gaji per OPD';

    protected static string $view = 'filament.pages.batch-salary-deduction';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }

    public const EXPORT_PERMISSION = 'export_savings_recap';

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
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('rows', $this->buildRows($get('agency_id'), $get('period_month')))),
                        Forms\Components\DatePicker::make('period_month')
                            ->label('Periode')
                            ->displayFormat('F Y')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('rows', $this->buildRows($get('agency_id'), $get('period_month'))))
                            ->helperText('Bulan potong gaji. Anggota yang sudah disetor periode ini akan dilewati otomatis.'),
                    ]),
                Forms\Components\Section::make('Anggota Aktif')
                    ->icon('heroicon-o-user-group')
                    ->description('Aktifkan "Ikut" untuk anggota yang disetor, lalu centang jenis simpanannya dan sesuaikan nominal bila perlu.')
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
                                    ->columns(3)
                                    ->visible(fn (Get $get): bool => (bool) $get('include'))
                                    ->schema([
                                        Forms\Components\Hidden::make('savings_type'),
                                        Forms\Components\Hidden::make('done'),
                                        Forms\Components\TextInput::make('type_label')
                                            ->label('Jenis')
                                            ->disabled()
                                            ->dehydrated(false),
                                        Forms\Components\Toggle::make('include')
                                            ->label('Ikut')
                                            ->inline(false)
                                            // Sudah disetor → terkunci (tak bisa dicentang ulang).
                                            ->disabled(fn (Get $get): bool => (bool) $get('done')),
                                        MoneyInput::make('amount')
                                            ->label('Nominal')
                                            ->dehydrated()
                                            ->disabled(fn (Get $get): bool => (bool) $get('done')
                                                || in_array($get('savings_type'), SavingsDepositResource::LOCKED_AMOUNT_TYPES, true))
                                            // Locked types nominalnya di-derive server-side → tak wajib diisi client.
                                            ->required(fn (Get $get): bool => (bool) $get('include')
                                                && ! (bool) $get('done')
                                                && ! in_array($get('savings_type'), SavingsDepositResource::LOCKED_AMOUNT_TYPES, true))
                                            ->minValue(1),
                                    ]),
                            ])
                            ->visible(fn (Get $get): bool => filled($get('agency_id'))),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Baris anggota aktif OPD terpilih. Tiap anggota membawa daftar jenis
     * simpanannya sendiri (`lines`) untuk diisi per-orang.
     *
     * @return list<array{member_id:string, member_label:string, include:bool, lines:list<array<string, mixed>>}>
     */
    protected function buildRows(?string $agencyId, mixed $period = null): array
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
                'lines' => $this->buildMemberTypeLines($m, $period),
            ])
            ->all();
    }

    /**
     * Jenis simpanan untuk satu anggota: wajib (prefill golongan), pokok &
     * wajib_belanja (nominal tetap ketentuan koperasi), + hari_raya hanya bila
     * ada program aktif yang memuat periode. Default tercentang: wajib & hari_raya.
     *
     * @return list<array{savings_type:string, type_label:string, include:bool, amount:?string, done:bool}>
     */
    protected function buildMemberTypeLines(Member $member, mixed $period): array
    {
        $settings = app(CooperativeSettings::class);

        $registration = $this->holidayRegistrationFor($member->getKey(), $period);

        $periodDate = filled($period) ? Carbon::parse($period)->startOfMonth()->toDateString() : null;

        $lines = [
            ['savings_type' => 'wajib', 'amount' => $member->mandatory_savings_amount === null ? null : (string) $member->mandatory_savings_amount],
            ['savings_type' => 'pokok', 'amount' => (string) $settings->savings_pokok_amount],
            ['savings_type' => 'wajib_belanja', 'amount' => (string) $settings->savings_wajib_belanja_amount],
        ];

        if ($registration !== null) {
            $lines[] = ['savings_type' => 'hari_raya', 'amount' => (string) $registration->monthly_amount];
        }

        return array_map(function (array $line) use ($member, $periodDate, $registration): array {
            $type = $line['savings_type'];

            $done = $this->typeAlreadyDeposited($member->getKey(), $type, $periodDate, $registration);

            return [
                'savings_type' => $type,
                'type_label' => SavingsDepositResource::SAVINGS_TYPES[$type].($done ? ' — sudah disetor' : ''),
                'include' => ! $done && in_array($type, SavingsDepositResource::DEFAULT_INCLUDED_TYPES, true),
                'amount' => $line['amount'],
                'done' => $done,
            ];
        }, $lines);
    }

    protected function typeAlreadyDeposited(string $memberId, string $type, ?string $periodDate, ?MemberHolidaySaving $registration): bool
    {
        if ($type === 'pokok') {
            return SavingsDeposit::hasActivePokok($memberId);
        }

        if ($type === 'hari_raya') {
            return $registration !== null
                && SavingsDeposit::hasActiveDeposit($memberId, 'hari_raya', sprintf('%04d-01-01', $registration->period_year));
        }

        return $periodDate !== null && SavingsDeposit::hasActiveDeposit($memberId, $type, $periodDate);
    }

    /**
     * Setoran satu jenis untuk anggota dengan nominal & periode yang ditegakkan
     * server-side: wajib pakai nominal baris (editable); pokok/wajib_belanja
     * pakai ketentuan koperasi; hari_raya pakai registrasi + periode = tahun
     * program (konsisten dgn setoran manual). Null = jenis tak berlaku (di-skip).
     *
     * @return array{type:string, amount:string, period_month:string}|null
     */
    protected function depositForType(string $memberId, string $type, string $period, ?string $rowAmount): ?array
    {
        $settings = app(CooperativeSettings::class);

        $periodDate = Carbon::parse($period)->startOfMonth()->toDateString();

        return match ($type) {
            'wajib' => ['type' => 'wajib', 'amount' => (string) ($rowAmount ?? '0'), 'period_month' => $periodDate],
            'pokok' => ['type' => 'pokok', 'amount' => (string) $settings->savings_pokok_amount, 'period_month' => $periodDate],
            'wajib_belanja' => ['type' => 'wajib_belanja', 'amount' => (string) $settings->savings_wajib_belanja_amount, 'period_month' => $periodDate],
            'hari_raya' => $this->hariRayaDeposit($memberId, $period),
            default => null,
        };
    }

    /**
     * @return array{type:string, amount:string, period_month:string}|null
     */
    private function hariRayaDeposit(string $memberId, string $period): ?array
    {
        $registration = $this->holidayRegistrationFor($memberId, $period);

        if ($registration === null) {
            return null;
        }

        return [
            'type' => 'hari_raya',
            'amount' => (string) $registration->monthly_amount,
            'period_month' => sprintf('%04d-01-01', $registration->period_year),
        ];
    }

    private function holidayRegistrationFor(string $memberId, mixed $period): ?MemberHolidaySaving
    {
        if (blank($period)) {
            return null;
        }

        return SavingsDepositResource::activeHolidayRegistration(
            $memberId,
            Carbon::parse($period)->startOfMonth()->toDateString(),
        );
    }

    public function process(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $agency = Agency::findOrFail($state['agency_id']);

        $period = $state['period_month'];

        $rows = collect($state['rows'] ?? [])
            ->filter(fn (array $r): bool => (bool) ($r['include'] ?? false))
            ->map(function (array $r) use ($period): array {
                $deposits = collect($r['lines'] ?? [])
                    ->filter(fn (array $line): bool => (bool) ($line['include'] ?? false))
                    ->map(fn (array $line): ?array => $this->depositForType(
                        (string) $r['member_id'],
                        (string) $line['savings_type'],
                        $period,
                        $line['amount'] ?? null,
                    ))
                    ->filter()
                    ->values()
                    ->all();

                return ['member_id' => $r['member_id'], 'deposits' => $deposits];
            })
            ->filter(fn (array $row): bool => $row['deposits'] !== [])
            ->values()
            ->all();

        if ($rows === []) {
            Notification::make()
                ->warning()
                ->title('Tidak ada setoran dipilih')
                ->body('Aktifkan minimal satu anggota dan centang minimal satu jenis simpanannya.')
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

        $this->data['rows'] = $this->buildRows($state['agency_id'], $state['period_month']);
    }

    public function getPeriodLabel(): string
    {
        $period = $this->data['period_month'] ?? null;

        return $period ? Carbon::parse($period)->translatedFormat('F Y') : '-';
    }
}
