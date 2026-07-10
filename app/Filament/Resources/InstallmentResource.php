<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\InstallmentResource\Pages;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstallmentResource extends Resource
{
    protected static ?string $model = Installment::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Pinjaman';

    protected static ?string $navigationLabel = 'Angsuran';

    protected static ?string $modelLabel = 'Angsuran';

    protected static ?string $pluralModelLabel = 'Angsuran';

    protected static ?int $navigationSort = 10;

    public const PAYMENT_METHODS = [
        'potong_gaji' => 'Potong Gaji',
        'manual' => 'Manual',
    ];

    /** @return array<string, string> Pinjaman aktif anggota, dibedakan tgl + nominal. */
    public static function activeLoanOptions(mixed $memberId): array
    {
        if (blank($memberId)) {
            return [];
        }

        return Loan::query()
            ->where('member_id', $memberId)
            ->where('status', 'Cair')
            ->orderByDesc('disbursement_date')
            ->get()
            ->mapWithKeys(fn (Loan $loan): array => [
                $loan->id => sprintf(
                    '%s — cair %s — Rp %s',
                    $loan->loan_number,
                    $loan->disbursement_date?->format('d/m/Y'),
                    number_format((float) $loan->principal_amount, 0, ',', '.'),
                ),
            ])
            ->all();
    }

    /** @return array<int|string, string> Jadwal belum bayar untuk pinjaman. */
    public static function unpaidScheduleOptions(mixed $loanId): array
    {
        if (blank($loanId)) {
            return [];
        }

        return InstallmentSchedule::query()
            ->where('loan_id', $loanId)
            ->where('status', 'Belum Bayar')
            ->orderBy('installment_seq')
            ->limit(1)
            ->get()
            ->mapWithKeys(fn (InstallmentSchedule $s): array => [
                $s->id => sprintf(
                    'Angsuran #%d — jatuh tempo %s — tagihan Rp %s',
                    $s->installment_seq,
                    $s->due_date?->format('d/m/Y'),
                    number_format((float) $s->total_due, 0, ',', '.'),
                ),
            ])
            ->all();
    }

    public static function prefillFromSchedule(mixed $scheduleId, Set $set): void
    {
        $schedule = InstallmentSchedule::find($scheduleId);

        if ($schedule === null) {
            return;
        }

        $set('amount_paid', self::rupiah($schedule->total_due));
    }

    private static function rupiah(string|int|float|null $value): string
    {
        return (string) (int) round((float) $value);
    }

    public static function scheduleBillDetail(mixed $scheduleId): HtmlString
    {
        $schedule = InstallmentSchedule::find($scheduleId);

        if ($schedule === null) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Pilih angsuran untuk melihat rincian tagihan.</span>');
        }

        $rows = [
            ['Angsuran ke', '#'.$schedule->installment_seq],
            ['Jatuh tempo', $schedule->due_date?->format('d M Y') ?? '—'],
            ['Pokok', self::idr($schedule->principal_due)],
            ['Jasa / Bunga', self::idr($schedule->interest_due)],
            ['Tabungan Berjangka', self::idr($schedule->time_deposit_due)],
            ['Total Tagihan', self::idr($schedule->total_due)],
        ];

        $body = collect($rows)->map(function (array $row): string {
            $isTotal = $row[0] === 'Total Tagihan';
            $class = $isTotal
                ? 'flex justify-between py-1.5 mt-1 border-t border-gray-200 dark:border-white/10 font-semibold text-gray-950 dark:text-white'
                : 'flex justify-between py-1.5 text-gray-700 dark:text-gray-300';

            return sprintf('<div class="%s"><span>%s</span><span>%s</span></div>', $class, $row[0], $row[1]);
        })->implode('');

        return new HtmlString(
            '<div class="text-sm rounded-lg bg-gray-50 px-4 py-2 ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10">'.$body.'</div>'
        );
    }

    private static function idr(string|int|float|null $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    public static function canReverse(Installment $record): bool
    {
        return ! $record->is_reversal
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    /** @return array<int, Component> */
    public static function reverseFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('reason')
                ->label('Alasan Reversal')
                ->required()->minLength(5)->maxLength(65535)
                ->helperText('Wajib, minimal 5 karakter. Tercatat di audit. Jika pelunasan, pengembalian SWP/Tab ikut dibatalkan.'),
        ];
    }

    public static function performReversal(Installment $record, array $data): void
    {
        try {
            app(LoanPaymentService::class)->reverse($record, (string) $data['reason']);

            Notification::make()->success()
                ->title('Reversal berhasil')
                ->body('Pembayaran dibatalkan; jadwal kembali Belum Bayar.')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Reversal ditolak')->body($e->getMessage())->send();
        }
    }

    public static function printReceipt(Installment $installment): StreamedResponse
    {
        $installment->loadMissing(['loan.member', 'recordedBy']);

        $pdf = Pdf::loadView('pdf.installment-receipt', [
            'installment' => $installment,
            'paymentMethodLabel' => self::PAYMENT_METHODS[$installment->payment_method] ?? $installment->payment_method,
            'printedAt' => now()->format('d M Y H:i'),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'kuitansi-angsuran-'.$installment->installment_number.'.pdf',
        );
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
            Forms\Components\Section::make('Pembayaran Angsuran')
                ->description('Pilih pinjaman aktif & angsuran, lalu masukkan nominal yang benar-benar diterima.')
                ->icon('heroicon-o-credit-card')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('member_id')
                        ->label('Anggota')
                        ->options(fn (): array => Member::query()->orderBy('full_name')->pluck('full_name', 'id')->all())
                        ->searchable()->preload()->required()->live()->dehydrated(false)
                        ->afterStateUpdated(fn (Set $set) => $set('loan_id', null) === $set('schedule_id', null)),
                    Forms\Components\Select::make('loan_id')
                        ->label('Pinjaman Aktif')
                        ->options(fn (Get $get): array => self::activeLoanOptions($get('member_id')))
                        ->required()->live()->dehydrated(false)->native(false)
                        ->afterStateUpdated(fn (Set $set) => $set('schedule_id', null))
                        ->helperText('Dibedakan dari tanggal pencairan & nominal.'),
                    Forms\Components\Select::make('schedule_id')
                        ->label('Angsuran (jatuh tempo)')
                        ->options(fn (Get $get): array => self::unpaidScheduleOptions($get('loan_id')))
                        ->required()->live()->native(false)
                        ->afterStateUpdated(fn (?string $state, Set $set) => self::prefillFromSchedule($state, $set)),
                    Forms\Components\Placeholder::make('bill_detail')
                        ->label('Rincian Tagihan')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => filled($get('schedule_id')))
                        ->content(fn (Get $get): HtmlString => self::scheduleBillDetail($get('schedule_id'))),
                    Forms\Components\Select::make('payment_method')
                        ->label('Metode Bayar')->options(self::PAYMENT_METHODS)
                        ->default('potong_gaji')->required()->native(false),
                    MoneyInput::make('amount_paid')->label('Nominal Dibayar')->required()
                        ->helperText('Total uang yang benar-benar diterima. Tidak boleh kurang dari tagihan; kelebihan tampil sebagai "Kelebihan Bayar" di kuitansi dan dikreditkan ke Simpanan Sukarela anggota.')
                        ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $schedule = InstallmentSchedule::find($get('schedule_id'));
                            if ($schedule !== null && bccomp((string) (int) round((float) $value), (string) $schedule->total_due, 0) < 0) {
                                $fail('Nominal tidak boleh kurang dari tagihan Rp '.number_format((float) $schedule->total_due, 0, ',', '.').'.');
                            }
                        }),
                    Forms\Components\DatePicker::make('payment_date')->label('Tanggal Bayar')->default(now())->required(),
                    Forms\Components\SpatieMediaLibraryFileUpload::make('bukti')
                        ->collection('bukti')->label('Bukti Pembayaran')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->downloadable()->openable()->columnSpanFull()
                        ->helperText('Gambar (JPG/PNG/WebP) atau PDF — slip/foto/kuitansi pendukung nominal diterima.'),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(2)->schema([
                Infolists\Components\TextEntry::make('installment_number')->label('No. Angsuran')->badge()->color('primary')->copyable(),
                Infolists\Components\TextEntry::make('loan.loan_number')->label('Pinjaman')->copyable(),
                Infolists\Components\TextEntry::make('loan.member.full_name')->label('Anggota'),
                Infolists\Components\TextEntry::make('amount_paid')->label('Dibayar')->money('IDR')->weight('bold')->color('success'),
                Infolists\Components\TextEntry::make('breakdown_principal')->label('Piutang SP')->money('IDR')
                    ->state(fn (Installment $record): string => $record->breakdown()['principal']),
                Infolists\Components\TextEntry::make('breakdown_interest')->label('Bunga SP')->money('IDR')
                    ->state(fn (Installment $record): string => $record->breakdown()['interest']),
                Infolists\Components\TextEntry::make('breakdown_time_deposit')->label('Tab. Berjangka')->money('IDR')
                    ->state(fn (Installment $record): string => $record->breakdown()['time_deposit']),
                Infolists\Components\TextEntry::make('breakdown_other')->label('Kelebihan Bayar')->money('IDR')
                    ->state(fn (Installment $record): string => $record->breakdown()['other']),
                Infolists\Components\TextEntry::make('remaining_principal')->label('Sisa Pokok')->money('IDR')
                    ->state(fn (Installment $record): string => $record->loan->remainingPrincipal()),
                Infolists\Components\TextEntry::make('payment_date')->label('Tgl Bayar')->date('d M Y'),
                Infolists\Components\IconEntry::make('is_reversal')->label('Reversal')->boolean(),
            ]),
            Infolists\Components\Section::make('Bukti Pembayaran')->schema([
                Infolists\Components\ViewEntry::make('bukti')
                    ->hiddenLabel()
                    ->view('filament.installment-bukti')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('installment_number')->label('No.')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('loan.member.full_name')->label('Anggota')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('loan.loan_number')->label('Pinjaman')->searchable(),
                Tables\Columns\TextColumn::make('amount_paid')->label('Dibayar')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('payment_method')->label('Metode')->badge()
                    ->formatStateUsing(fn (string $state): string => self::PAYMENT_METHODS[$state] ?? $state)->toggleable(),
                Tables\Columns\TextColumn::make('payment_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\IconColumn::make('bukti')->label('Bukti')->boolean()
                    ->getStateUsing(fn (Installment $record): bool => $record->hasMedia('bukti'))
                    ->trueIcon('heroicon-o-paper-clip')->falseIcon('heroicon-o-minus-small')
                    ->tooltip(fn (Installment $record): string => $record->hasMedia('bukti') ? 'Ada bukti — buka di detail' : 'Tanpa bukti')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_reversal')->label('Reversal')->boolean()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')->label('Metode')->options(self::PAYMENT_METHODS),
                Tables\Filters\TernaryFilter::make('is_reversal')->label('Reversal'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('printReceipt')
                        ->label('Kuitansi')->icon('heroicon-o-printer')->color('gray')
                        ->action(fn (Installment $record): StreamedResponse => self::printReceipt($record)),
                    Tables\Actions\Action::make('reverse')
                        ->label('Reversal')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                        ->visible(fn (Installment $record): bool => self::canReverse($record))
                        ->form(self::reverseFormSchema())
                        ->requiresConfirmation()
                        ->modalHeading('Reversal Pembayaran')
                        ->action(fn (Installment $record, array $data) => self::performReversal($record, $data)),
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
            'index' => Pages\ListInstallments::route('/'),
            'create' => Pages\CreateInstallment::route('/create'),
            'view' => Pages\ViewInstallment::route('/{record}'),
        ];
    }
}
