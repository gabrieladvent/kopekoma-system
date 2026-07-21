<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Enums\LoanStatus;
use App\Filament\Resources\LoanResource;
use App\Models\Loan;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LoansRelationManager extends RelationManager
{
    protected static string $relationship = 'loans';

    protected static ?string $title = 'Histori Pinjaman';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function isReadOnly(): bool
    {
        return true;
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
                // Label & warna badge di-drive enum LoanStatus (HasLabel/HasColor).
                Tables\Columns\TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(LoanStatus::options()),
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
