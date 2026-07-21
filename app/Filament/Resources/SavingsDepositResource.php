<?php

namespace App\Filament\Resources;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Filament\Resources\SavingsDepositResource\Pages;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Settings\CooperativeSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SavingsDepositResource extends Resource
{
    protected static ?string $model = SavingsDeposit::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static ?string $navigationGroup = 'Utama';

    protected static ?string $navigationLabel = 'Setoran Simpanan';

    protected static ?string $modelLabel = 'Setoran';

    protected static ?string $pluralModelLabel = 'Setoran Simpanan';

    protected static ?int $navigationSort = 20;

    public const SAVINGS_TYPES = [
        'pokok' => 'Simpanan Pokok',
        'wajib' => 'Simpanan Wajib',
        'sukarela' => 'Simpanan Sukarela',
        'hari_raya' => 'Simpanan Hari Raya',
        'wajib_belanja' => 'Wajib Belanja',
    ];

    public const DEPOSIT_METHODS = [
        'setor_sendiri' => 'Setor Sendiri',
        'potong_gaji' => 'Potong Gaji',
    ];

    public const DEPOSITED_BY = [
        'anggota' => 'Anggota',
        'bendahara' => 'Bendahara',
    ];

    public const LOCKED_AMOUNT_TYPES = ['pokok', 'wajib_belanja', 'hari_raya'];

    public const DEFAULT_INCLUDED_TYPES = ['wajib', 'hari_raya'];

    public static function activeHolidayRegistration(mixed $memberId, mixed $depositDate): ?MemberHolidaySaving
    {
        if (blank($memberId) || blank($depositDate)) {
            return null;
        }

        $date = Carbon::parse($depositDate)->toDateString();

        return MemberHolidaySaving::query()
            ->where('member_id', $memberId)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    /**
     * Opsi jenis simpanan, difilter per anggota + tanggal setor: `hari_raya`
     * hanya muncul bila ada program Hari Raya AKTIF yang rentangnya memuat
     * `deposit_date`. Di luar rentang / tak terdaftar → opsi hilang.
     *
     * @return array<string, string>
     */
    public static function savingsTypeOptions(mixed $memberId, mixed $depositDate = null): array
    {
        $options = self::SAVINGS_TYPES;

        if (self::activeHolidayRegistration($memberId, $depositDate) === null) {
            unset($options['hari_raya']);
        }

        return $options;
    }

    public static function holidayMonthlyAmount(mixed $memberId, mixed $depositDate): ?string
    {
        $registration = self::activeHolidayRegistration($memberId, $depositDate);

        return $registration?->monthly_amount === null
            ? null
            : (string) $registration->monthly_amount;
    }

    /**
     * Bangun baris jenis simpanan untuk anggota + tanggal + periode: satu baris
     * per jenis yang berlaku DAN belum disetor (aturan 1x per periode; pokok 1x
     * seumur keanggotaan). hari_raya hanya bila ada program aktif yang memuat
     * tanggal. Nominal wajib/locked di-prefill; sukarela dibiarkan kosong. State
     * sebelumnya (centang, nominal, idempotency_key) dipertahankan saat rebuild.
     *
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    public static function buildLines(mixed $memberId, mixed $depositDate, mixed $periodMonth = null, array $existing = []): array
    {
        if (blank($memberId)) {
            return [];
        }

        $previous = collect($existing)->keyBy('savings_type');

        return collect(self::savingsTypeOptions($memberId, $depositDate))
            ->reject(fn (string $label, string $type): bool => self::typeAlreadyDeposited($type, $memberId, $depositDate, $periodMonth))
            ->map(function (string $label, string $type) use ($memberId, $depositDate, $previous): array {
                $prior = $previous->get($type);

                return [
                    'savings_type' => $type,
                    'type_label' => $label,
                    'include' => $prior['include'] ?? in_array($type, self::DEFAULT_INCLUDED_TYPES, true),
                    'amount' => $prior['amount'] ?? self::prefillAmount($type, $memberId, $depositDate),
                    'idempotency_key' => $prior['idempotency_key'] ?? (string) Str::uuid(),
                ];
            })
            ->values()
            ->all();
    }

    public static function effectivePeriod(string $type, mixed $memberId, mixed $depositDate, mixed $periodMonth): ?string
    {
        if ($type === 'hari_raya') {
            $registration = self::activeHolidayRegistration($memberId, $depositDate);

            return $registration === null ? null : sprintf('%04d-01-01', $registration->period_year);
        }

        return blank($periodMonth) ? null : Carbon::parse($periodMonth)->startOfMonth()->toDateString();
    }

    public static function typeAlreadyDeposited(string $type, mixed $memberId, mixed $depositDate, mixed $periodMonth): bool
    {
        if (blank($memberId)) {
            return false;
        }

        if ($type === 'pokok') {
            return SavingsDeposit::hasActivePokok($memberId);
        }

        $period = self::effectivePeriod($type, $memberId, $depositDate, $periodMonth);

        return $period !== null && SavingsDeposit::hasActiveDeposit($memberId, $type, $period);
    }

    /**
     * Label jenis yang DISEMBUNYIKAN karena sudah disetor — untuk catatan di form.
     *
     * @return list<string>
     */
    public static function hiddenTypeLabels(mixed $memberId, mixed $depositDate, mixed $periodMonth): array
    {
        if (blank($memberId)) {
            return [];
        }

        return collect(self::savingsTypeOptions($memberId, $depositDate))
            ->filter(fn (string $label, string $type): bool => self::typeAlreadyDeposited($type, $memberId, $depositDate, $periodMonth))
            ->values()
            ->all();
    }

    private static function prefillAmount(string $type, mixed $memberId, mixed $depositDate): ?string
    {
        $settings = app(CooperativeSettings::class);

        $amount = match ($type) {
            'pokok' => $settings->savings_pokok_amount,
            'wajib_belanja' => $settings->savings_wajib_belanja_amount,
            'wajib' => Member::find($memberId)?->mandatory_savings_amount,
            'hari_raya' => self::holidayMonthlyAmount($memberId, $depositDate),
            default => null,
        };

        return blank($amount) ? null : (string) (int) round((float) $amount);
    }

    /**
     * Override nominal di SERVER untuk locked types (jangan percaya field disabled
     * dari client): pokok/wajib_belanja dari settings, hari_raya dari registrasi.
     * `wajib` & `sukarela` dipakai apa adanya (sudah divalidasi di form: prefill
     * editable & minimal). Validasi min sukarela + registrasi hari_raya ditegakkan
     * sebagai rule form (lihat field) agar error menempel ke field yang benar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceAmountRules(array $data): array
    {
        $settings = app(CooperativeSettings::class);

        switch ($data['savings_type'] ?? null) {
            case 'pokok':
                $data['amount'] = (string) $settings->savings_pokok_amount;
                break;

            case 'wajib_belanja':
                $data['amount'] = (string) $settings->savings_wajib_belanja_amount;
                break;

            case 'hari_raya':
                $registration = self::activeHolidayRegistration($data['member_id'] ?? null, $data['deposit_date'] ?? null);

                if ($registration !== null) {
                    $data['amount'] = (string) $registration->monthly_amount;

                    $data['period_month'] = sprintf('%04d-01-01', $registration->period_year);
                }
                break;
        }

        return $data;
    }

    public static function canReverse(SavingsDeposit $record): bool
    {
        return ! $record->is_reversal
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    /**
     * Schema form alasan reversal — wajib, min 5 karakter, tercatat di audit (D3).
     *
     * @return array<int, Component>
     */
    public static function reverseFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('reason')
                ->label('Alasan Reversal')
                ->required()
                ->minLength(5)
                ->maxLength(65535)
                ->helperText('Wajib, minimal 5 karakter. Akan tercatat di log audit.'),
        ];
    }

    public static function performReversal(SavingsDeposit $record, array $data): void
    {
        try {
            app(ReverseTransaction::class)($record, (string) $data['reason']);

            Notification::make()
                ->success()
                ->title('Reversal berhasil')
                ->body('Transaksi-lawan telah dibuat dan saldo tersesuaikan.')
                ->send();
        } catch (CannotReverseTransaction $e) {
            Notification::make()
                ->danger()
                ->title('Reversal ditolak')
                ->body($e->getMessage())
                ->send();
        }
    }

    public static function printSlip(SavingsDeposit $deposit): StreamedResponse
    {
        $deposit->loadMissing(['member', 'recordedBy']);

        $pdf = Pdf::loadView('pdf.savings-slip', [
            'deposit' => $deposit,
            'savingsTypeLabel' => self::SAVINGS_TYPES[$deposit->savings_type] ?? $deposit->savings_type,
            'depositMethodLabel' => self::DEPOSIT_METHODS[$deposit->deposit_method] ?? $deposit->deposit_method,
            'depositedByLabel' => self::DEPOSITED_BY[$deposit->deposited_by] ?? $deposit->deposited_by,
            'printedAt' => now()->format('d M Y H:i'),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'slip-setoran-'.$deposit->transaction_number.'.pdf',
        );
    }

    public static function typeColor(string $state): string
    {
        return match ($state) {
            'pokok' => 'primary',
            'wajib' => 'info',
            'sukarela' => 'success',
            'hari_raya' => 'warning',
            'wajib_belanja' => 'gray',
            default => 'gray',
        };
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Anggota & Info Setoran')
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Anggota')
                            ->relationship('member', 'full_name')
                            ->getOptionLabelFromRecordUsing(fn (Member $record): string => "{$record->member_number} — {$record->full_name}")
                            ->searchable(['member_number', 'full_name'])
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('lines', self::buildLines($get('member_id'), $get('deposit_date'), $get('period_month'))))
                            ->helperText('Anggota pemilik setoran.'),
                        Forms\Components\DatePicker::make('deposit_date')
                            ->label('Tanggal Setor')
                            ->required()
                            ->default(now())
                            ->maxDate(now())
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('lines', self::buildLines($get('member_id'), $get('deposit_date'), $get('period_month'), $get('lines') ?? [])))
                            ->helperText('Mengubah tanggal memunculkan/menyembunyikan Hari Raya sesuai rentang program aktif.'),
                        Forms\Components\DatePicker::make('period_month')
                            ->label('Periode (Bulan)')
                            ->displayFormat('F Y')
                            ->default(now()->startOfMonth())
                            ->required()
                            ->live()
                            ->dehydrated()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('lines', self::buildLines($get('member_id'), $get('deposit_date'), $get('period_month'), $get('lines') ?? [])))
                            ->helperText('Periode penyetoran. Satu jenis hanya boleh sekali per periode. Untuk Hari Raya, periode otomatis ke tahun program.'),
                        Forms\Components\Select::make('deposit_method')
                            ->label('Metode Setor')
                            ->options(self::DEPOSIT_METHODS)
                            ->default('setor_sendiri')
                            ->required()
                            ->native(false),
                        Forms\Components\Select::make('deposited_by')
                            ->label('Disetor Oleh')
                            ->options(self::DEPOSITED_BY)
                            ->default('anggota')
                            ->required()
                            ->native(false)
                            ->helperText('Pihak yang menyetor dana.'),
                        Forms\Components\TextInput::make('reference_number')
                            ->label('No. Referensi')
                            ->maxLength(50)
                            ->placeholder('Opsional')
                            ->helperText('Nomor bukti/transfer bila ada.'),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->placeholder('Opsional'),
                    ]),
                Forms\Components\Section::make('Jenis Simpanan')
                    ->icon('heroicon-o-banknotes')
                    ->description('Centang jenis simpanan yang disetor dan sesuaikan nominalnya. Minimal satu jenis dengan nominal lebih dari 0.')
                    ->visible(fn (Get $get): bool => filled($get('member_id')))
                    ->schema([
                        Forms\Components\Placeholder::make('already_deposited_note')
                            ->hiddenLabel()
                            ->visible(fn (Get $get): bool => self::hiddenTypeLabels($get('member_id'), $get('deposit_date'), $get('period_month')) !== [])
                            ->content(fn (Get $get): string => 'Disembunyikan karena sudah disetor untuk periode ini: '
                                .implode(', ', self::hiddenTypeLabels($get('member_id'), $get('deposit_date'), $get('period_month'))).'.'),
                        Forms\Components\Repeater::make('lines')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(3)
                            ->schema([
                                Forms\Components\Hidden::make('savings_type'),
                                Forms\Components\Hidden::make('idempotency_key'),
                                Forms\Components\TextInput::make('type_label')
                                    ->label('Jenis')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\Toggle::make('include')
                                    ->label('Ikut')
                                    ->inline(false)
                                    ->live(),
                                MoneyInput::make('amount')
                                    ->label('Nominal')
                                    ->dehydrated()
                                    ->disabled(fn (Get $get): bool => in_array($get('savings_type'), self::LOCKED_AMOUNT_TYPES, true))
                                    // Locked types nominalnya di-derive server-side → tak wajib diisi client.
                                    ->required(fn (Get $get): bool => (bool) $get('include')
                                        && ! in_array($get('savings_type'), self::LOCKED_AMOUNT_TYPES, true))
                                    ->minValue(fn (Get $get): int|float => $get('savings_type') === 'sukarela'
                                        ? (float) app(CooperativeSettings::class)->savings_sukarela_min
                                        : 1)
                                    ->helperText(fn (Get $get): string => match ($get('savings_type')) {
                                        'pokok', 'wajib_belanja', 'hari_raya' => 'Nominal terkunci dari ketentuan koperasi/registrasi.',
                                        'sukarela' => 'Minimal sesuai ketentuan koperasi.',
                                        'wajib' => 'Default dari golongan anggota; boleh disesuaikan.',
                                        default => '',
                                    }),
                            ]),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\Grid::make(['default' => 2, 'sm' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('transaction_number')
                                    ->label('No. Transaksi')
                                    ->badge()
                                    ->color('primary')
                                    ->icon('heroicon-m-hashtag')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('savings_type')
                                    ->label('Jenis')
                                    ->badge()
                                    ->color(fn (string $state): string => static::typeColor($state))
                                    ->formatStateUsing(fn (string $state): string => self::SAVINGS_TYPES[$state] ?? $state),
                                Infolists\Components\TextEntry::make('amount')
                                    ->label('Nominal')
                                    ->money('IDR')
                                    ->weight('bold')
                                    ->color('success'),
                                Infolists\Components\IconEntry::make('is_reversal')
                                    ->label('Reversal')
                                    ->boolean(),
                            ]),
                    ]),
                Infolists\Components\Grid::make(2)
                    ->schema([
                        Infolists\Components\Section::make('Anggota & Periode')
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('member.full_name')
                                    ->label('Anggota')
                                    ->icon('heroicon-o-user'),
                                Infolists\Components\TextEntry::make('member.member_number')
                                    ->label('No. Anggota')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('deposit_date')
                                    ->label('Tanggal Setor')
                                    ->date('d M Y'),
                                Infolists\Components\TextEntry::make('period_month')
                                    ->label('Periode')
                                    ->date('F Y')
                                    ->placeholder('—'),
                            ]),
                        Infolists\Components\Section::make('Detail Setoran')
                            ->icon('heroicon-o-document-text')
                            ->columns(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('deposit_method')
                                    ->label('Metode')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => self::DEPOSIT_METHODS[$state] ?? $state),
                                Infolists\Components\TextEntry::make('deposited_by')
                                    ->label('Disetor Oleh')
                                    ->formatStateUsing(fn (string $state): string => self::DEPOSITED_BY[$state] ?? $state),
                                Infolists\Components\TextEntry::make('reference_number')
                                    ->label('No. Referensi')
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('recordedBy.name')
                                    ->label('Dicatat Oleh')
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('notes')
                                    ->label('Catatan')
                                    ->columnSpanFull()
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_number')
                    ->label('No. Transaksi')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('member.full_name')
                    ->label('Anggota')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('savings_type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (string $state): string => static::typeColor($state))
                    ->formatStateUsing(fn (string $state): string => self::SAVINGS_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('deposit_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('deposit_method')
                    ->label('Metode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::DEPOSIT_METHODS[$state] ?? $state)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_reversal')
                    ->label('Reversal')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('savings_type')
                    ->label('Jenis Simpanan')
                    ->options(self::SAVINGS_TYPES),
                Tables\Filters\SelectFilter::make('deposit_method')
                    ->label('Metode')
                    ->options(self::DEPOSIT_METHODS),
                Tables\Filters\TernaryFilter::make('is_reversal')
                    ->label('Reversal'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('printSlip')
                        ->label('Cetak Slip')
                        ->icon('heroicon-o-printer')
                        ->color('gray')
                        ->action(fn (SavingsDeposit $record): StreamedResponse => static::printSlip($record)),
                    Tables\Actions\Action::make('reverse')
                        ->label('Reversal')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->visible(fn (SavingsDeposit $record): bool => static::canReverse($record))
                        ->form(static::reverseFormSchema())
                        ->requiresConfirmation()
                        ->modalHeading('Reversal Setoran')
                        ->modalDescription('Membuat transaksi-lawan. Baris asli tidak dihapus.')
                        ->action(fn (SavingsDeposit $record, array $data) => static::performReversal($record, $data)),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AuditTrailRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSavingsDeposits::route('/'),
            'create' => Pages\CreateSavingsDeposit::route('/create'),
            'view' => Pages\ViewSavingsDeposit::route('/{record}'),
        ];
    }
}
