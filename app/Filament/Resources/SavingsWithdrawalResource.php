<?php

namespace App\Filament\Resources;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotProcessWithdrawal;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Filament\Resources\SavingsWithdrawalResource\Pages;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use App\Services\SavingsBalanceService;
use App\Services\WithdrawalWorkflow;
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
use Illuminate\Support\Str;

class SavingsWithdrawalResource extends Resource
{
    protected static ?string $model = SavingsWithdrawal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static ?string $navigationGroup = 'Simpanan';

    // "Pencairan Simpanan" (bukan sekadar "Pencairan") agar tak rancu dengan
    // pencairan pinjaman yang lahir di Modul Pinjaman (Minggu 3).
    protected static ?string $navigationLabel = 'Pencairan Simpanan';

    protected static ?string $modelLabel = 'Pencairan Simpanan';

    protected static ?string $pluralModelLabel = 'Pencairan Simpanan';

    protected static ?int $navigationSort = 20;

    /**
     * Jenis yang bisa dicairkan Minggu 2 (D8) — whitelist, bukan enum penuh.
     * `pokok`/`wajib`/`swp`/`tabungan_berjangka` sengaja tak ditawarkan.
     *
     * @var array<string, string>
     */
    public const WITHDRAWAL_TYPES = [
        'hari_raya' => 'Simpanan Hari Raya',
        'sukarela' => 'Simpanan Sukarela',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'acc' => 'Disetujui',
        'cair' => 'Cair',
        'ditolak' => 'Ditolak',
    ];

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'draft' => 'gray',
            'acc' => 'info',
            'cair' => 'success',
            'ditolak' => 'danger',
            default => 'gray',
        };
    }

    public static function typeColor(string $state): string
    {
        return match ($state) {
            'hari_raya' => 'warning',
            'sukarela' => 'success',
            default => 'gray',
        };
    }

    /**
     * Saldo tersedia untuk anggota + jenis (+ tahun untuk hari_raya). Null bila
     * data belum lengkap. Dipakai untuk hint form & validasi nominal dini.
     */
    public static function availableBalance(mixed $memberId, ?string $type, mixed $year = null): ?string
    {
        if (blank($memberId) || blank($type)) {
            return null;
        }

        $member = Member::find($memberId);

        if ($member === null) {
            return null;
        }

        $service = app(SavingsBalanceService::class);

        if ($type === 'hari_raya') {
            return blank($year) ? null : $service->holidayBalance($member, (int) $year);
        }

        if ($type === 'sukarela') {
            return $service->balanceByType($member, 'sukarela');
        }

        return null;
    }

    /**
     * Tahun program Hari Raya anggota yang masih punya saldo > 0 (kandidat cair).
     *
     * @return array<int, string>
     */
    public static function holidayYearOptions(mixed $memberId): array
    {
        if (blank($memberId)) {
            return [];
        }

        $member = Member::find($memberId);

        if ($member === null) {
            return [];
        }

        $options = [];

        foreach (app(SavingsBalanceService::class)->holidayBalancesByYear($member) as $year => $balance) {
            if (bccomp($balance, '0', 2) > 0) {
                $options[$year] = sprintf('%d (saldo Rp %s)', $year, number_format((float) $balance, 0, ',', '.'));
            }
        }

        return $options;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Idempotency (D4): satu render = satu key.
                Forms\Components\Hidden::make('idempotency_key')
                    ->default(fn (): string => (string) Str::uuid()),
                Forms\Components\Section::make('Pengajuan Pencairan')
                    ->icon('heroicon-o-banknotes')
                    ->description('Pencairan dibuat sebagai draft. ACC & pencairan dana dilakukan pengurus.')
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
                            ->afterStateUpdated(function (Set $set): void {
                                $set('savings_type', null);
                                $set('period_year', null);
                                $set('amount', null);
                            }),
                        Forms\Components\Select::make('savings_type')
                            ->label('Jenis Simpanan')
                            ->options(self::WITHDRAWAL_TYPES)
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('period_year', null);
                                $set('amount', null);
                            })
                            ->helperText('Hanya Hari Raya & Sukarela yang dapat dicairkan saat ini.'),
                        Forms\Components\Select::make('period_year')
                            ->label('Tahun Program (Hari Raya)')
                            ->options(fn (Get $get): array => self::holidayYearOptions($get('member_id')))
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('amount', null))
                            ->required(fn (Get $get): bool => $get('savings_type') === 'hari_raya')
                            ->visible(fn (Get $get): bool => $get('savings_type') === 'hari_raya')
                            ->helperText('Saldo Hari Raya dikelola per tahun program.'),
                        MoneyInput::make('amount')
                            ->label('Nominal Pencairan')
                            ->required()
                            ->minValue(1)
                            ->helperText(function (Get $get): string {
                                $balance = self::availableBalance($get('member_id'), $get('savings_type'), $get('period_year'));

                                return $balance === null
                                    ? 'Pilih anggota & jenis untuk melihat saldo tersedia.'
                                    : 'Saldo tersedia: Rp '.number_format((float) $balance, 0, ',', '.');
                            })
                            // Validasi dini: nominal tak boleh melebihi saldo tersedia.
                            ->rule(fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                                $balance = self::availableBalance($get('member_id'), $get('savings_type'), $get('period_year'));

                                if ($balance !== null && bccomp((string) $value, $balance, 2) > 0) {
                                    $fail('Nominal melebihi saldo tersedia (Rp '.number_format((float) $balance, 0, ',', '.').').');
                                }
                            }),
                        Forms\Components\DatePicker::make('withdrawal_date')
                            ->label('Tanggal Pengajuan')
                            ->required()
                            ->default(now())
                            ->maxDate(now()),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->placeholder('Opsional'),
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
                                Infolists\Components\TextEntry::make('withdrawal_number')
                                    ->label('No. Pencairan')
                                    ->badge()
                                    ->color('primary')
                                    ->icon('heroicon-m-hashtag')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('savings_type')
                                    ->label('Jenis')
                                    ->badge()
                                    ->color(fn (string $state): string => static::typeColor($state))
                                    ->formatStateUsing(fn (string $state): string => self::WITHDRAWAL_TYPES[$state] ?? $state),
                                Infolists\Components\TextEntry::make('amount')
                                    ->label('Nominal')
                                    ->money('IDR')
                                    ->weight('bold')
                                    ->color('danger'),
                                Infolists\Components\TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (string $state): string => static::statusColor($state))
                                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                            ]),
                    ]),
                Infolists\Components\Grid::make(2)
                    ->schema([
                        Infolists\Components\Section::make('Anggota')
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('member.full_name')
                                    ->label('Anggota'),
                                Infolists\Components\TextEntry::make('member.member_number')
                                    ->label('No. Anggota')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('withdrawal_date')
                                    ->label('Tanggal Pengajuan')
                                    ->date('d M Y'),
                                Infolists\Components\TextEntry::make('period_year')
                                    ->label('Tahun Program')
                                    ->placeholder('—'),
                            ]),
                        Infolists\Components\Section::make('Workflow')
                            ->icon('heroicon-o-arrow-path')
                            ->columns(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('approvedBy.name')
                                    ->label('Disetujui Oleh')
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('approved_at')
                                    ->label('Waktu ACC')
                                    ->dateTime('d M Y H:i')
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('disbursed_at')
                                    ->label('Waktu Cair')
                                    ->dateTime('d M Y H:i')
                                    ->placeholder('—'),
                                Infolists\Components\IconEntry::make('is_reversal')
                                    ->label('Reversal')
                                    ->boolean(),
                                Infolists\Components\TextEntry::make('recordedBy.name')
                                    ->label('Diajukan Oleh')
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
                Tables\Columns\TextColumn::make('withdrawal_number')
                    ->label('No. Pencairan')
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
                    ->formatStateUsing(fn (string $state): string => self::WITHDRAWAL_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                Tables\Columns\TextColumn::make('withdrawal_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_year')
                    ->label('Tahun Program')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('approvedBy.name')
                    ->label('Disetujui Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_reversal')
                    ->label('Reversal')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('savings_type')
                    ->label('Jenis Simpanan')
                    ->options(self::WITHDRAWAL_TYPES),
                Tables\Filters\TernaryFilter::make('is_reversal')
                    ->label('Reversal'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    static::approveAction(),
                    static::disburseAction(),
                    static::rejectAction(),
                    static::reverseAction(),
                ]),
            ]);
    }

    // ── Aksi per-record: visible() (sembunyikan tombol) + Policy + guard body ──

    public static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label('ACC')
            ->icon('heroicon-o-check-circle')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Setujui Pencairan')
            ->modalDescription('Menyetujui pengajuan ini. Dana belum keluar sampai dicairkan.')
            ->visible(fn (SavingsWithdrawal $record): bool => $record->status === 'draft'
                && (auth()->user()?->can('approve', $record) ?? false))
            ->action(fn (SavingsWithdrawal $record) => static::runTransition('approve', $record));
    }

    public static function disburseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('disburse')
            ->label('Cairkan')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Cairkan Dana')
            ->modalDescription('Menandai pencairan sebagai cair. Saldo anggota akan berkurang.')
            ->visible(fn (SavingsWithdrawal $record): bool => $record->status === 'acc'
                && (auth()->user()?->can('disburse', $record) ?? false))
            ->action(fn (SavingsWithdrawal $record) => static::runTransition('disburse', $record));
    }

    public static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')
            ->label('Tolak')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Tolak Pencairan')
            ->modalDescription('Menolak pengajuan. Status ditolak bersifat final.')
            ->visible(fn (SavingsWithdrawal $record): bool => in_array($record->status, ['draft', 'acc'], true)
                && (auth()->user()?->can('approve', $record) ?? false))
            ->action(fn (SavingsWithdrawal $record) => static::runTransition('reject', $record));
    }

    public static function reverseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reverse')
            ->label('Reversal')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->form(static::reverseFormSchema())
            ->requiresConfirmation()
            ->modalHeading('Reversal Pencairan')
            ->modalDescription('Membuat transaksi-lawan untuk pencairan yang sudah cair. Baris asli tidak dihapus.')
            ->visible(fn (SavingsWithdrawal $record): bool => static::canReverse($record))
            ->action(fn (SavingsWithdrawal $record, array $data) => static::performReversal($record, $data));
    }

    /**
     * Reversal hanya untuk pencairan `cair` (D10), bukan reversal, dan ber-permission.
     */
    public static function canReverse(SavingsWithdrawal $record): bool
    {
        return $record->status === 'cair'
            && ! $record->is_reversal
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    /**
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

    /**
     * Pesan sukses spesifik per transisi (lebih informatif daripada satu pesan
     * generik): tiap aksi menjelaskan efeknya ke dana & langkah berikutnya.
     *
     * @var array<string, array{title:string, body:string}>
     */
    private const TRANSITION_NOTICES = [
        'approve' => [
            'title' => 'Pencairan disetujui (ACC)',
            'body' => 'Pengajuan disetujui. Saldo belum berkurang — dana baru keluar saat dicairkan.',
        ],
        'disburse' => [
            'title' => 'Dana dicairkan',
            'body' => 'Pencairan ditandai cair dan saldo anggota telah berkurang. Tercatat di log audit.',
        ],
        'reject' => [
            'title' => 'Pencairan ditolak',
            'body' => 'Pengajuan ditolak dan bersifat final. Saldo tidak berubah.',
        ],
    ];

    /**
     * Jalankan transisi state machine via engine; domain error → notif danger.
     */
    public static function runTransition(string $transition, SavingsWithdrawal $record): void
    {
        $workflow = app(WithdrawalWorkflow::class);

        try {
            match ($transition) {
                'approve' => $workflow->approve($record),
                'disburse' => $workflow->disburse($record),
                'reject' => $workflow->reject($record),
            };

            $notice = self::TRANSITION_NOTICES[$transition];

            Notification::make()
                ->success()
                ->title($notice['title'])
                ->body($notice['body'])
                ->send();
        } catch (CannotProcessWithdrawal $e) {
            Notification::make()
                ->danger()
                ->title('Aksi ditolak')
                ->body($e->getMessage())
                ->send();
        }
    }

    public static function performReversal(SavingsWithdrawal $record, array $data): void
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

    public static function getRelations(): array
    {
        return [
            AuditTrailRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSavingsWithdrawals::route('/'),
            'create' => Pages\CreateSavingsWithdrawal::route('/create'),
            'edit' => Pages\EditSavingsWithdrawal::route('/{record}/edit'),
            'view' => Pages\ViewSavingsWithdrawal::route('/{record}'),
        ];
    }
}
