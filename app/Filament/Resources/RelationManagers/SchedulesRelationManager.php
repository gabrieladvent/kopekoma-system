<?php

namespace App\Filament\Resources\RelationManagers;

use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Services\LoanArrearsService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Progres angsuran satu pinjaman (ADR 2026-06-24). Read-only & presentasi murni:
 * sumber baris = installment_schedules (rencana N baris sejak akad), realisasi
 * pembayaran ditarik dari installments asli (is_reversal = false). Nempel di
 * halaman View Pinjaman (bukan menu navigasi baru).
 *
 * Status tri-state memakai definisi tunggakan yang SAMA dengan
 * InstallmentSchedule::scopeOverdue() (Belum Bayar + due_date < hari ini) agar
 * konsisten dengan LoanArrearsService — tidak ada definisi tunggakan kedua.
 */
class SchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'schedules';

    protected static ?string $title = 'Progres Angsuran';

    protected static ?string $icon = 'heroicon-o-list-bullet';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function statusLabel(InstallmentSchedule $schedule): string
    {
        if ($schedule->status === 'Terbayar') {
            return 'Terbayar';
        }

        // Mirror scopeOverdue: due_date < hari ini (perbandingan tanggal saja).
        return $schedule->due_date && $schedule->due_date->lt(today())
            ? 'Nunggak'
            : 'Belum Jatuh Tempo';
    }

    public static function statusColor(string $label): string
    {
        return match ($label) {
            'Terbayar' => 'success',
            'Nunggak' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Pembayaran "hidup" untuk sebuah jadwal: angsuran asli terbaru (bukan reversal).
     * Pakai relasi yang sudah di-eager-load bila ada (hindari N+1 di tabel).
     */
    public static function actualPayment(InstallmentSchedule $schedule): ?Installment
    {
        if ($schedule->relationLoaded('installments')) {
            return $schedule->installments->firstWhere('is_reversal', false);
        }

        return Installment::query()
            ->where('schedule_id', $schedule->getKey())
            ->where('is_reversal', false)
            ->latest()
            ->first();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Progres Angsuran')
            ->description(fn (): Htmlable => static::progressHeader($this->getOwnerRecord()))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'installments' => fn ($q) => $q->where('is_reversal', false)->latest(),
            ]))
            ->defaultSort('installment_seq')
            ->paginated([25, 50, 'all'])
            ->columns([
                Tables\Columns\TextColumn::make('installment_seq')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_due')
                    ->label('Tagihan')
                    ->money('IDR'),
                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Dibayar')
                    ->money('IDR')
                    ->placeholder('—')
                    ->state(fn (InstallmentSchedule $record): ?string => $record->status === 'Terbayar'
                        ? static::actualPayment($record)?->amount_paid
                        : null),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (InstallmentSchedule $record): string => static::statusLabel($record))
                    ->color(fn (string $state): string => static::statusColor($state)),
                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Tgl Bayar')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->state(fn (InstallmentSchedule $record): mixed => $record->status === 'Terbayar'
                        ? static::actualPayment($record)?->payment_date
                        : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Terbayar' => 'Terbayar',
                        'Belum Bayar' => 'Belum Bayar',
                    ]),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalWidth(MaxWidth::TwoExtraLarge),
            ])
            ->bulkActions([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Jadwal Angsuran')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('installment_seq')->label('Angsuran ke-'),
                    Infolists\Components\TextEntry::make('due_date')->label('Jatuh Tempo')->date('d M Y'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->state(fn (InstallmentSchedule $record): string => static::statusLabel($record))
                        ->color(fn (string $state): string => static::statusColor($state)),
                    Infolists\Components\TextEntry::make('total_due')->label('Total Tagihan')->money('IDR'),
                    Infolists\Components\TextEntry::make('principal_due')->label('Pokok')->money('IDR'),
                    Infolists\Components\TextEntry::make('interest_due')->label('Jasa')->money('IDR'),
                    Infolists\Components\TextEntry::make('time_deposit_due')->label('Tab. Berjangka')->money('IDR'),
                ]),
            Infolists\Components\Section::make('Realisasi Pembayaran')
                ->columns(2)
                ->visible(fn (InstallmentSchedule $record): bool => $record->status === 'Terbayar')
                ->schema([
                    Infolists\Components\TextEntry::make('paid_number')
                        ->label('No. Angsuran')
                        ->state(fn (InstallmentSchedule $record): ?string => static::actualPayment($record)?->installment_number)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('paid_date')
                        ->label('Tgl Bayar')
                        ->state(fn (InstallmentSchedule $record): mixed => static::actualPayment($record)?->payment_date)
                        ->date('d M Y')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('paid_amount')
                        ->label('Total Dibayar')
                        ->state(fn (InstallmentSchedule $record): ?string => static::actualPayment($record)?->amount_paid)
                        ->money('IDR')
                        ->weight('bold')
                        ->color('success'),
                    Infolists\Components\TextEntry::make('paid_remaining')
                        ->label('Sisa Pokok')
                        ->state(fn (InstallmentSchedule $record): ?string => static::actualPayment($record)?->remaining_principal)
                        ->money('IDR'),
                ]),
        ]);
    }

    /**
     * Header progres: progress bar persentase lunas + ringkasan
     * "X/N lunas · Sisa pokok Rp … · M nunggak".
     */
    public static function progressHeader(Loan $loan): Htmlable
    {
        $total = (int) $loan->schedules()->count();
        $paid = (int) $loan->schedules()->where('status', 'Terbayar')->count();
        $overdue = app(LoanArrearsService::class)->overdueCount($loan);
        $percent = $total > 0 ? (int) round($paid / $total * 100) : 0;
        $remaining = number_format((float) static::remainingPrincipal($loan), 0, ',', '.');

        $right = "{$paid}/{$total} lunas";
        if ($overdue > 0) {
            $right .= " · <span class=\"text-danger-600 dark:text-danger-400 font-medium\">{$overdue} nunggak</span>";
        }

        return new HtmlString(<<<HTML
            <div class="space-y-1.5">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{$percent}% terbayar</span>
                    <span class="text-gray-600 dark:text-gray-400">{$right}</span>
                </div>
                <div class="w-full h-2.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                    <div class="h-2.5 rounded-full bg-primary-600" style="width: {$percent}%"></div>
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Sisa pokok Rp {$remaining}</div>
            </div>
        HTML);
    }

    /**
     * Sisa pokok = remaining_principal angsuran asli terbaru; bila belum ada
     * pembayaran sama sekali, pakai principal_amount penuh.
     */
    private static function remainingPrincipal(Loan $loan): string
    {
        $latest = Installment::query()
            ->where('loan_id', $loan->id)
            ->where('is_reversal', false)
            ->latest()
            ->first();

        return (string) ($latest?->remaining_principal ?? $loan->principal_amount);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return method_exists($ownerRecord, static::$relationship);
    }
}
