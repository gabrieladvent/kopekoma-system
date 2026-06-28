<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Filament\Resources\LoanResource;
use App\Models\Loan;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Histori pinjaman satu anggota (read-only). Menampilkan SEMUA pinjaman —
 * Cair, Lunas, dan Dibatalkan — agar jejak penuh terlihat di detail anggota.
 * Nempel di halaman View Member (bukan menu navigasi baru).
 */
class LoansRelationManager extends RelationManager
{
    protected static string $relationship = 'loans';

    protected static ?string $title = 'Histori Pinjaman';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'Lunas' => 'success',
            'Dibatalkan' => 'gray',
            default => 'info',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Histori Pinjaman')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('loan_number')->label('No. Pinjaman')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('loan_type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state): string => LoanResource::LOAN_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('principal_amount')->label('Jumlah')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('disbursement_date')->label('Tgl Pencairan')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => static::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(['Cair' => 'Cair', 'Lunas' => 'Lunas', 'Dibatalkan' => 'Dibatalkan']),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Buka')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return method_exists($ownerRecord, static::$relationship);
    }
}
