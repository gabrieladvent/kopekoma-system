<?php

namespace App\Filament\Resources;

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

    protected static ?string $navigationGroup = 'Pinjaman';

    protected static ?string $navigationLabel = 'Pinjaman';

    protected static ?string $modelLabel = 'Pinjaman';

    protected static ?string $pluralModelLabel = 'Pinjaman';

    protected static ?int $navigationSort = 10;

    public const LOAN_TYPES = [
        'jangka_panjang' => 'Jangka Panjang',
        'jangka_pendek' => 'Jangka Pendek (Sebrakan)',
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

    /**
     * Koreksi salah-input (D3/2d): hanya bila BELUM ada angsuran terbayar &
     * pemakai punya ability `reverse`. Tidak ada "pembatalan pinjaman" bisnis.
     */
    public static function canCorrect(Loan $record): bool
    {
        return ! self::hasPayments($record)
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
            'printedAt' => now()->format('d M Y H:i'),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'tanda-terima-'.$loan->loan_number.'.pdf',
        );
    }

    public static function performCorrection(Loan $record, array $data): void
    {
        if (self::hasPayments($record)) {
            Notification::make()
                ->danger()
                ->title('Koreksi ditolak')
                ->body('Pinjaman sudah memiliki angsuran terbayar — koreksi hanya untuk salah input sebelum ada pembayaran.')
                ->send();

            return;
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
                ->log('Koreksi salah-input pinjaman: '.$data['reason']);

            InstallmentSchedule::where('loan_id', $record->id)->delete();
            $record->delete();
        });

        Notification::make()
            ->success()
            ->title('Pinjaman dikoreksi')
            ->body('Record pinjaman salah-input beserta jadwalnya telah dihapus dan dicatat di audit.')
            ->send();
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

                            if ($get('loan_type') === 'jangka_panjang' && $amount <= 1_000_000) {
                                $fail('Jangka Panjang harus di atas Rp 1.000.000. Untuk ≤ Rp 1.000.000 gunakan Sebrakan (Jangka Pendek).');
                            }

                            if ($get('loan_type') === 'jangka_pendek' && $amount > 1_000_000) {
                                $fail('Sebrakan (Jangka Pendek) maksimal Rp 1.000.000. Di atas itu gunakan Jangka Panjang.');
                            }
                        })
                        ->helperText(fn (Get $get): string => $get('loan_type') === 'jangka_pendek'
                            ? 'Maksimal Rp 1.000.000 untuk Sebrakan.'
                            : 'Di atas Rp 1.000.000 untuk jangka panjang.'),
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
                        ->label('Biaya Admin (1%)')
                        ->content(fn (Get $get): string => self::previewMoney($get, 'admin_fee')),
                    Forms\Components\Placeholder::make('preview_swp')
                        ->label('SWP (1%)')
                        ->content(fn (Get $get): string => self::previewMoney($get, 'swp_amount')),
                    Forms\Components\Placeholder::make('preview_disbursed')
                        ->label('Dana Diterima')
                        ->content(fn (Get $get): string => self::previewMoney($get, 'disbursed_amount')),
                    Forms\Components\Placeholder::make('preview_pokok')
                        ->label('Pokok / bulan')
                        ->content(fn (Get $get): string => self::previewConstant($get, 'monthly_principal')),
                    Forms\Components\Placeholder::make('preview_jasa')
                        ->label('Jasa / bulan')
                        ->content(fn (Get $get): string => self::previewConstant($get, 'monthly_interest')),
                    Forms\Components\Placeholder::make('preview_tab')
                        ->label('Tab. Berjangka / bulan')
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

    /**
     * MoneyInput menampilkan nilai ter-mask (pemisah ribuan '.'); buang agar bcmath valid.
     */
    private static function sanitizePrincipal(mixed $value): string
    {
        return preg_replace('/[^0-9]/', '', (string) $value) ?? '';
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
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')->badge()
                            ->color(fn (string $state): string => $state === 'Lunas' ? 'success' : 'info'),
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
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => $state === 'Lunas' ? 'success' : 'info'),
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
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(['Cair' => 'Cair', 'Lunas' => 'Lunas']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('printReceipt')
                    ->label('Tanda Terima')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn (Loan $record): StreamedResponse => self::printReceipt($record)),
                Tables\Actions\Action::make('correct')
                    ->label('Koreksi')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (Loan $record): bool => self::canCorrect($record))
                    ->form(self::correctionFormSchema())
                    ->requiresConfirmation()
                    ->modalHeading('Koreksi Salah-Input Pinjaman')
                    ->modalDescription('Hanya untuk pinjaman salah input yang belum punya angsuran. Record & jadwalnya dihapus, dicatat di audit.')
                    ->action(fn (Loan $record, array $data) => self::performCorrection($record, $data)),
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
