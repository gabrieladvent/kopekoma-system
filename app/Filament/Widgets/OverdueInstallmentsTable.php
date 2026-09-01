<?php

namespace App\Filament\Widgets;

use App\Models\InstallmentSchedule;
use App\Services\LoanArrearsService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OverdueInstallmentsTable extends TableWidget
{
    /**
     * Polling dimatikan (ADR 2026-08-28). Bawaan Filament 5 detik, dan angka
     * tunggakan kini menghitung tagihan efektif per pinjaman — satu tab dashboard
     * yang dibiarkan terbuka mengulang seluruh rangkaian itu setiap 5 detik.
     * Angka tunggakan tak pernah berubah secepat itu; refresh halaman sudah cukup.
     */
    protected static ?string $pollingInterval = null;

    /**
     * Memo per-request. Tanpa ini `effectiveBills()` dihitung ulang untuk SETIAP
     * baris, dan tiap hitungan menyentuh saldo titipan tiap pinjaman — tabel
     * 10 baris jadi puluhan query.
     *
     * @var array<int|string, string>|null
     */
    private ?array $effectiveBillsMemo = null;

    /** @return array<int|string, string> */
    private function effectiveBills(Table $table): array
    {
        return $this->effectiveBillsMemo ??= app(LoanArrearsService::class)
            ->effectiveBills($table->getRecords());
    }

    protected static ?string $heading = 'Tunggakan Angsuran Terbaru';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                InstallmentSchedule::query()
                    ->overdue()
                    ->with('loan.member')
                    ->orderBy('due_date')
            )
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('Tidak ada tunggakan')
            ->emptyStateDescription('Semua angsuran yang jatuh tempo sudah terbayar.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('loan.member.full_name')
                    ->label('Anggota')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('loan.loan_number')
                    ->label('No. Pinjaman')
                    ->searchable(),
                TextColumn::make('installment_seq')
                    ->label('Angsuran ke-')
                    ->alignCenter(),
                TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('days_overdue')
                    ->label('Terlambat')
                    ->state(fn (InstallmentSchedule $record): string => $record->due_date->diffInDays(now()).' hari')
                    ->badge()
                    ->color('danger'),
                // Tagihan EFEKTIF, bukan kontraktual (ADR 2026-08-28 item 2f).
                // Titipan Pokok satu kantong per pinjaman, jadi ia dikuras dalam
                // urutan angsuran — memotongnya di setiap baris secara terpisah
                // akan melaporkan tunggakan lebih kecil dari kenyataan.
                TextColumn::make('total_due')
                    ->label('Nominal')
                    ->state(fn (InstallmentSchedule $record, Table $table): string => $this->effectiveBills($table)[$record->getKey()] ?? (string) $record->total_due)
                    ->money('IDR')
                    ->alignEnd()
                    ->weight('bold'),
            ]);
    }
}
