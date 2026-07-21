<?php

namespace App\Filament\Resources;

use App\Enums\LoanStatus;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Filament\Resources\RelationManagers\SchedulesRelationManager;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanBlacklist;
use App\Models\Member;
use App\Services\LoanArrearsService;
use App\Services\LoanCalculator;
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
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Utama';

    protected static ?string $navigationLabel = 'Pinjaman';

    protected static ?string $modelLabel = 'Pinjaman';

    protected static ?string $pluralModelLabel = 'Pinjaman';

    protected static ?int $navigationSort = 10;

    public const LOAN_TYPES = [
        'jangka_panjang' => 'Jangka Panjang',
        'jangka_pendek' => 'Jangka Pendek (Sebrakan)',
    ];

    public const DISBURSEMENT_METHODS = [
        'tunai' => 'Tunai',
        'transfer' => 'Transfer',
    ];

    public static function hasActiveBlacklist(mixed $memberId): bool
    {
        if (blank($memberId)) {
            return false;
        }

        return LoanBlacklist::query()
            ->where('member_id', $memberId)
            ->where('is_active', true)
            ->exists();
    }

    public static function typeColor(string $state): string
    {
        return $state === 'jangka_panjang' ? 'primary' : 'warning';
    }

    public static function canCorrect(Loan $record): bool
    {
        return $record->status === LoanStatus::Cair
            && ! self::hasPayments($record)
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public static function hasPayments(Loan $record): bool
    {
        return Installment::query()
            ->where('loan_id', $record->id)
            ->where('is_reversal', false)
            ->exists();
    }

    /**
     * @return array<int, Component>
     */
    public static function correctionFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('reason')
                ->label('Alasan Koreksi')
                ->required()
                ->minLength(5)
                ->maxLength(65535)
                ->helperText('Wajib, minimal 5 karakter. Tercatat di log audit.'),
        ];
    }

    public static function printReceipt(Loan $loan): StreamedResponse
    {
        $loan->loadMissing(['member', 'recordedBy', 'schedules']);

        $pdf = Pdf::loadView('pdf.loan-receipt', [
            'loan' => $loan,
            'loanTypeLabel' => self::LOAN_TYPES[$loan->loan_type] ?? $loan->loan_type,
            'adminRateLabel' => self::amountRateLabel($loan->admin_fee, $loan->principal_amount),
            'swpRateLabel' => self::amountRateLabel($loan->swp_amount, $loan->principal_amount),
            'printedAt' => now()->format('d M Y H:i'),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'tanda-terima-'.$loan->loan_number.'.pdf',
        );
    }

    /**
     * Batalkan pinjaman salah-input: record DIPERTAHANKAN sebagai histori
     * (status → Dibatalkan), jadwal proyeksinya dibuang.
     *
     * @return bool true bila dibatalkan; false bila ditolak (bukan Cair / sudah ada angsuran).
     */
    public static function performCorrection(Loan $record, array $data): bool
    {
        if ($record->status !== LoanStatus::Cair || self::hasPayments($record)) {
            Notification::make()
                ->danger()
                ->title('Pembatalan ditolak')
                ->body('Hanya pinjaman Cair tanpa angsuran terbayar yang bisa dibatalkan (salah input sebelum ada pembayaran).')
                ->send();

            return false;
        }

        DB::transaction(function () use ($record, $data): void {
            activity()
                ->performedOn($record)
                ->causedBy(auth()->id())
                ->event('koreksi')
                ->withProperties([
                    'loan_number' => $record->loan_number,
                    'member_id' => $record->member_id,
                    'principal_amount' => $record->principal_amount,
                ])
                ->log('Pembatalan salah-input pinjaman: '.$data['reason']);

            InstallmentSchedule::where('loan_id', $record->id)->delete();

            $record->update(['status' => LoanStatus::Dibatalkan]);
        });

        Notification::make()
            ->success()
            ->title('Pinjaman dibatalkan')
            ->body('Pinjaman ditandai Dibatalkan dan tetap tersimpan sebagai histori; jadwalnya dibersihkan. Tercatat di audit.')
            ->send();

        return true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Pinjaman')
                ->description('Pinjaman dicatat setelah disetujui (ACC) di luar sistem.')
                ->icon('heroicon-o-banknotes')
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
                        ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                            if (self::hasActiveBlacklist($value)) {
                                $fail('Anggota ini sedang dalam daftar blacklist pinjaman — tidak dapat mengajukan pinjaman baru.');
                            }
                        })
                        ->helperText('Anggota peminjam (yang sudah ACC).'),
                    Forms\Components\Select::make('loan_type')
                        ->label('Jenis Pinjaman')
                        ->options(self::LOAN_TYPES)
                        ->required()
                        ->native(false)
                        ->live()
                        ->default('jangka_panjang')
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state === 'jangka_pendek') {
                                $set('term_months', 1);
                            }
                        }),
                    MoneyInput::make('principal_amount')
                        ->label('Jumlah Pinjaman Diajukan')
                        ->required()
                        ->live(onBlur: true)
                        ->minValue(1)
                        ->rule(static fn (Get $get): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $amount = (int) self::sanitizePrincipal($value);
                            $max = self::shortTermMax();
                            $maxRp = self::rupiah($max);

                            if ($get('loan_type') === 'jangka_panjang' && $amount <= $max) {
                                $fail("Jangka Panjang harus di atas {$maxRp}. Untuk ≤ {$maxRp} gunakan Sebrakan (Jangka Pendek).");
                            }

                            if ($get('loan_type') === 'jangka_pendek' && $amount > $max) {
                                $fail("Sebrakan (Jangka Pendek) maksimal {$maxRp}. Di atas itu gunakan Jangka Panjang.");
                            }
                        })
                        ->helperText(fn (Get $get): string => $get('loan_type') === 'jangka_pendek'
                            ? 'Maksimal '.self::rupiah(self::shortTermMax()).' untuk Sebrakan.'
                            : 'Di atas '.self::rupiah(self::shortTermMax()).' untuk jangka panjang.'),
                    Forms\Components\TextInput::make('term_months')
                        ->label('Jangka Waktu (bulan)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(12)
                        ->live(onBlur: true)
                        ->disabled(fn (Get $get): bool => $get('loan_type') === 'jangka_pendek')
                        ->dehydrated()
                        ->helperText('Sebrakan otomatis 1 bulan.'),
                    Forms\Components\DatePicker::make('disbursement_date')
                        ->label('Tanggal Pencairan')
                        ->required()
                        ->default(now())
                        ->live()
                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                            if (blank($get('first_due_date')) && filled($state)) {
                                $set('first_due_date', Carbon::parse($state)->addMonth()->toDateString());
                            }
                        }),
                    Forms\Components\DatePicker::make('first_due_date')
                        ->label('Jatuh Tempo Pertama')
                        ->required()
                        ->default(now()->addMonth())
                        ->helperText('Default satu bulan setelah pencairan.'),
                    Forms\Components\Select::make('disbursement_method')
                        ->label('Jenis Pencairan')
                        ->options(self::DISBURSEMENT_METHODS)
                        ->placeholder('Pilih jenis pencairan')
                        ->live()
                        ->afterStateUpdated(function (string $state, Get $get, Set $set): void {
                            if ($state !== 'transfer') {
                                $set('disbursement_bank', null);

                                $set('disbursement_account_number', null);

                                return;
                            }

                            $member = Member::find($get('member_id'));

                            $set('disbursement_bank', $member?->bank_name);

                            $set('disbursement_account_number', $member?->payroll_account_number);
                        }),
                    Forms\Components\TextInput::make('disbursement_bank')
                        ->label('Bank Tujuan')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('disbursement_method') === 'transfer')
                        ->required()
                        ->helperText('Otomatis terisi dari rekening payroll anggota; ubah bila berbeda.'),
                    Forms\Components\TextInput::make('disbursement_account_number')
                        ->label('No. Rekening Tujuan')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('disbursement_method') === 'transfer')
                        ->required(),
                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->maxLength(65535)
                        ->columnSpanFull()
                        ->placeholder('Opsional'),
                ]),
            Forms\Components\Section::make('Rincian Otomatis')
                ->description('Dihitung server dari ketentuan koperasi — tidak dapat diubah manual.')
                ->icon('heroicon-o-calculator')
                ->columns(3)
                ->schema([
                    Forms\Components\Placeholder::make('preview_admin')
                        ->label(self::rateLabel('Biaya Admin', app(CooperativeSettings::class)->loan_admin_fee_rate))
                        ->content(fn (Get $get): string => self::previewMoney($get, 'admin_fee')),
                    Forms\Components\Placeholder::make('preview_swp')
                        ->label(self::rateLabel('SWP', app(CooperativeSettings::class)->loan_swp_rate))
                        ->content(fn (Get $get): string => self::previewMoney($get, 'swp_amount')),
                    Forms\Components\Placeholder::make('preview_disbursed')
                        ->label('Dana Diterima')
                        ->content(fn (Get $get): string => self::previewMoney($get, 'disbursed_amount')),
                    Forms\Components\Placeholder::make('preview_pokok')
                        ->label('Pokok / bulan')
                        ->content(fn (Get $get): string => self::previewConstant($get, 'monthly_principal')),
                    Forms\Components\Placeholder::make('preview_jasa')
                        ->label(self::rateLabel('Jasa', app(CooperativeSettings::class)->loan_interest_rate).' / bulan')
                        ->content(fn (Get $get): string => self::previewConstant($get, 'monthly_interest')),
                    Forms\Components\Placeholder::make('preview_tab')
                        ->label(self::rateLabel('Tab. Berjangka', app(CooperativeSettings::class)->loan_time_deposit_rate).' / bulan')
                        ->content(fn (Get $get): string => self::previewConstant($get, 'monthly_time_deposit')),
                ]),
            Forms\Components\Section::make('Peringatan & Kapasitas')
                ->icon('heroicon-o-exclamation-triangle')
                ->visible(fn (Get $get): bool => filled($get('member_id')))
                ->schema([
                    Forms\Components\Placeholder::make('arrears_warning')
                        ->label('Riwayat Angsuran')
                        ->content(function (Get $get): string {
                            $member = Member::find($get('member_id'));

                            return $member ? (app(LoanArrearsService::class)->arrearsWarning($member) ?? 'Tidak ada riwayat angsuran terlewat.') : '—';
                        }),
                    Forms\Components\Placeholder::make('deduction_load')
                        ->label('Potongan Gaji Berjalan (info)')
                        ->content(function (Get $get): string {
                            $member = Member::find($get('member_id'));

                            return $member
                                ? 'Rp '.number_format((float) app(LoanArrearsService::class)->monthlyDeductionLoad($member), 2, ',', '.').' / bulan (pinjaman aktif + simpanan wajib). Verifikasi kemampuan potong gaji tetap manual.'
                                : '—';
                        }),
                ]),
            Forms\Components\Section::make('Dokumen Pinjaman')
                ->icon('heroicon-o-paper-clip')
                ->schema([
                    Forms\Components\SpatieMediaLibraryFileUpload::make('dokumen')
                        ->collection('dokumen')
                        ->label('Formulir / Tanda Terima')
                        ->multiple()
                        ->downloadable()
                        ->openable()
                        ->helperText('Unggah formulir/tanda terima pinjaman.'),
                ]),
        ]);
    }

    private static function sanitizePrincipal(mixed $value): string
    {
        return preg_replace('/[^0-9]/', '', (string) $value) ?? '';
    }

    private static function rateLabel(string $base, float $rate): string
    {
        return $base.' ('.self::formatPercent($rate).')';
    }

    private static function amountRateLabel(string|float|null $amount, string|float|null $base): string
    {
        $base = (float) $base;

        if ($base <= 0 || (float) $amount <= 0) {
            return '';
        }

        return ' ('.self::formatPercent((float) $amount / $base).')';
    }

    private static function formatPercent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 4, '.', ''), '0'), '.').'%';
    }

    private static function shortTermMax(): int
    {
        return (int) round((float) app(CooperativeSettings::class)->loan_short_term_max);
    }

    private static function rupiah(int|float|string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    private static function previewMoney(Get $get, string $key): string
    {
        $principal = self::sanitizePrincipal($get('principal_amount'));

        if (blank($principal)) {
            return '—';
        }

        $d = app(LoanCalculator::class)->disbursement((string) $get('loan_type'), $principal);

        return 'Rp '.number_format((float) $d[$key], 2, ',', '.');
    }

    private static function previewConstant(Get $get, string $key): string
    {
        $principal = self::sanitizePrincipal($get('principal_amount'));

        $term = (int) $get('term_months');

        if (blank($principal) || $term < 1) {
            return '—';
        }

        $c = app(LoanCalculator::class)->monthlyConstants((string) $get('loan_type'), $principal, $term);

        return 'Rp '.number_format((float) $c[$key], 2, ',', '.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\Grid::make(['default' => 2, 'sm' => 4])->schema([
                        Infolists\Components\TextEntry::make('loan_number')
                            ->label('No. Pinjaman')->badge()->color('primary')->copyable(),
                        Infolists\Components\TextEntry::make('loan_type')
                            ->label('Jenis')->badge()
                            ->color(fn (string $state): string => self::typeColor($state))
                            ->formatStateUsing(fn (string $state): string => self::LOAN_TYPES[$state] ?? $state),
                        // Label & warna badge di-drive enum LoanStatus (HasLabel/HasColor).
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')->badge(),
                        Infolists\Components\TextEntry::make('disbursed_amount')
                            ->label('Dana Diterima')->money('IDR')->weight('bold')->color('success'),
                    ]),
                ]),
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\Section::make('Pinjaman')->columns(2)->schema([
                    Infolists\Components\TextEntry::make('member.full_name')->label('Anggota'),
                    Infolists\Components\TextEntry::make('member.member_number')->label('No. Anggota')->copyable(),
                    Infolists\Components\TextEntry::make('principal_amount')->label('Jumlah Diajukan')->money('IDR'),
                    Infolists\Components\TextEntry::make('term_months')->label('Jangka Waktu')->suffix(' bulan'),
                    Infolists\Components\TextEntry::make('disbursement_date')->label('Tgl Pencairan')->date('d M Y'),
                    Infolists\Components\TextEntry::make('disbursement_method')->label('Jenis Pencairan')
                        ->formatStateUsing(fn (?string $state): string => self::DISBURSEMENT_METHODS[$state] ?? $state)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('disbursement_bank')->label('Bank Tujuan')
                        ->visible(fn (Loan $record): bool => $record->disbursement_method === 'transfer')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('disbursement_account_number')->label('No. Rekening Tujuan')
                        ->visible(fn (Loan $record): bool => $record->disbursement_method === 'transfer')
                        ->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('first_due_date')->label('Jatuh Tempo Pertama')->date('d M Y')->placeholder('—'),
                ]),
                Infolists\Components\Section::make('Potongan & Angsuran')->columns(2)->schema([
                    Infolists\Components\TextEntry::make('admin_fee')->label('Biaya Admin')->money('IDR'),
                    Infolists\Components\TextEntry::make('swp_amount')->label('SWP')->money('IDR'),
                    Infolists\Components\TextEntry::make('monthly_principal')->label('Pokok/bulan')->money('IDR'),
                    Infolists\Components\TextEntry::make('monthly_interest')->label('Jasa/bulan')->money('IDR'),
                    Infolists\Components\TextEntry::make('monthly_time_deposit')->label('Tab. Berjangka/bulan')->money('IDR'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('loan_number')->label('No. Pinjaman')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('member.full_name')->label('Anggota')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('loan_type')->label('Jenis')->badge()
                    ->color(fn (string $state): string => self::typeColor($state))
                    ->formatStateUsing(fn (string $state): string => self::LOAN_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('principal_amount')->label('Jumlah')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('disbursed_amount')->label('Diterima')->money('IDR')->toggleable(),
                Tables\Columns\TextColumn::make('disbursement_method')->label('Jenis Pencairan')->badge()
                    ->formatStateUsing(fn (?string $state): string => self::DISBURSEMENT_METHODS[$state] ?? $state)
                    ->placeholder('—')->toggleable(),
                // Label & warna badge di-drive enum LoanStatus (HasLabel/HasColor).
                Tables\Columns\TextColumn::make('status')->label('Status')->badge(),
                Tables\Columns\TextColumn::make('overdue')
                    ->label('Tunggakan')
                    ->state(fn (Loan $record): int => app(LoanArrearsService::class)->overdueCount($record))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} terlewat" : 'Lancar'),
                Tables\Columns\TextColumn::make('disbursement_date')->label('Pencairan')->date('d M Y')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('loan_type')->label('Jenis')->options(self::LOAN_TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(LoanStatus::options()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('printReceipt')
                        ->label('Tanda Terima')
                        ->icon('heroicon-o-printer')
                        ->color('gray')
                        ->action(fn (Loan $record): StreamedResponse => self::printReceipt($record)),
                    Tables\Actions\Action::make('correct')
                        ->label('Batalkan')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->visible(fn (Loan $record): bool => self::canCorrect($record))
                        ->form(self::correctionFormSchema())
                        ->requiresConfirmation()
                        ->modalHeading('Batalkan Pinjaman Salah-Input')
                        ->modalDescription('Hanya untuk pinjaman salah input yang belum punya angsuran. Pinjaman ditandai Dibatalkan (tetap tersimpan sebagai histori), jadwalnya dibersihkan, dicatat di audit.')
                        ->action(fn (Loan $record, array $data) => self::performCorrection($record, $data)),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SchedulesRelationManager::class,
            AuditTrailRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            'view' => Pages\ViewLoan::route('/{record}'),
        ];
    }
}
