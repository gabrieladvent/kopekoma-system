<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\GradeResource\Pages;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Grade;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GradeResource extends Resource
{
    protected static ?string $model = Grade::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Golongan';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Golongan';

    protected static ?string $pluralModelLabel = 'Golongan';

    /**
     * Generate a unique grade code (format: GOL-0001).
     */
    public static function generateCode(): string
    {
        do {
            $code = 'GOL-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Grade::where('code', $code)->exists());

        return $code;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(15)
                    ->unique(ignoreRecord: true)
                    ->placeholder('GOL-0001')
                    ->helperText('Kode unik golongan. Klik ikon untuk generate otomatis.')
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('generateCode')
                            ->icon('heroicon-m-sparkles')
                            ->tooltip('Generate kode otomatis')
                            ->action(fn (Set $set) => $set('code', static::generateCode())),
                    ),
                Forms\Components\TextInput::make('name')
                    ->label('Nama Golongan')
                    ->required()
                    ->maxLength(50)
                    ->placeholder('Golongan I')
                    ->helperText('Nama golongan kepegawaian.'),
                MoneyInput::make('mandatory_savings_amount')
                    ->label('Simpanan Wajib / Bulan')
                    ->required()
                    ->placeholder('50.000')
                    ->helperText('Nominal simpanan wajib per bulan untuk golongan ini.'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->helperText('Nonaktifkan bila golongan tidak lagi dipakai.'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Detail Golongan')
                    ->icon('heroicon-o-academic-cap')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('code')
                            ->label('Kode')
                            ->badge()
                            ->color('primary')
                            ->copyable(),
                        Infolists\Components\IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        Infolists\Components\TextEntry::make('name')
                            ->label('Nama Golongan')
                            ->columnSpanFull()
                            ->weight('bold')
                            ->size('lg'),
                        Infolists\Components\TextEntry::make('mandatory_savings_amount')
                            ->label('Simpanan Wajib / Bulan')
                            ->money('IDR')
                            ->badge()
                            ->color('success'),
                    ]),
                Infolists\Components\Section::make('Ringkasan')
                    ->icon('heroicon-o-chart-bar')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('members_count')
                            ->label('Jumlah Anggota')
                            ->state(fn (Grade $record): int => $record->members()->count())
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime('d M Y H:i'),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->label('Diperbarui')
                            ->dateTime('d M Y H:i'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Golongan')
                    ->searchable(),
                Tables\Columns\TextColumn::make('mandatory_savings_amount')
                    ->label('Simpanan Wajib / Bulan')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListGrades::route('/'),
            'create' => Pages\CreateGrade::route('/create'),
            'view' => Pages\ViewGrade::route('/{record}'),
            'edit' => Pages\EditGrade::route('/{record}/edit'),
        ];
    }
}
