<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgencyResource\Pages;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Agency;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AgencyResource extends Resource
{
    protected static ?string $model = Agency::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationLabel = 'OPD / Instansi';

    protected static ?string $modelLabel = 'OPD';

    protected static ?string $pluralModelLabel = 'OPD';

    /**
     * Generate a unique OPD code (format: OPD0001).
     */
    public static function generateCode(): string
    {
        do {
            $code = 'OPD'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Agency::where('agency_code', $code)->exists());

        return $code;
    }

    /**
     * Normalize an Indonesian phone number for storage as "+62XXXXXXXXXX".
     */
    public static function normalizePhone(?string $state): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $state);
        $digits = preg_replace('/^62/', '', (string) $digits);
        $digits = ltrim((string) $digits, '0');

        return $digits === '' ? null : '+62'.$digits;
    }

    /**
     * Strip the "+62" prefix for display in the edit form.
     */
    public static function localPhone(?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $state);
        $digits = preg_replace('/^62/', '', (string) $digits);

        return ltrim((string) $digits, '0') ?: null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('agency_code')
                    ->label('Kode OPD')
                    ->required()
                    ->maxLength(10)
                    ->unique(ignoreRecord: true)
                    ->placeholder('OPD0001')
                    ->helperText('Kode unik OPD. Klik ikon untuk generate otomatis.')
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('generateCode')
                            ->icon('heroicon-m-sparkles')
                            ->tooltip('Generate kode otomatis')
                            ->action(fn (Set $set) => $set('agency_code', static::generateCode())),
                    ),
                Forms\Components\TextInput::make('agency_name')
                    ->label('Nama OPD / Instansi')
                    ->required()
                    ->maxLength(150)
                    ->placeholder('Dinas Kesehatan')
                    ->helperText('Nama resmi OPD / instansi.'),
                Forms\Components\Textarea::make('address')
                    ->label('Alamat')
                    ->maxLength(65535)
                    ->placeholder('Jl. ... No. ...')
                    ->columnSpanFull(),
                Forms\Components\Select::make('payroll_treasurer')
                    ->label('Bendahara Gaji (PIC)')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'name')->all())
                    ->searchable()
                    ->preload()
                    ->placeholder('Pilih bendahara')
                    ->helperText('Diambil dari daftar pengguna.'),
                Forms\Components\TextInput::make('pic_phone_number')
                    ->label('No. HP PIC')
                    ->tel()
                    ->prefix('+62')
                    ->maxLength(15)
                    ->placeholder('81234567890')
                    ->helperText('Tanpa angka 0 di depan. Disimpan dengan awalan +62.')
                    ->formatStateUsing(fn (?string $state): ?string => static::localPhone($state))
                    ->dehydrateStateUsing(fn (?string $state): ?string => static::normalizePhone($state)),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options([
                        'Aktif' => 'Aktif',
                        'Non-Aktif' => 'Non-Aktif',
                    ])
                    ->default('Aktif')
                    ->required(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Identitas OPD')
                    ->icon('heroicon-o-building-office-2')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('agency_code')
                            ->label('Kode OPD')
                            ->badge()
                            ->color('primary')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'gray'),
                        Infolists\Components\TextEntry::make('agency_name')
                            ->label('Nama OPD / Instansi')
                            ->columnSpanFull()
                            ->weight('bold')
                            ->size('lg'),
                    ]),
                Infolists\Components\Section::make('Kontak & PIC')
                    ->icon('heroicon-o-user-circle')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('payroll_treasurer')
                            ->label('Bendahara Gaji (PIC)')
                            ->icon('heroicon-o-user')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('pic_phone_number')
                            ->label('No. HP PIC')
                            ->icon('heroicon-o-phone')
                            ->copyable()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('address')
                            ->label('Alamat')
                            ->icon('heroicon-o-map-pin')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
                Infolists\Components\Section::make('Ringkasan')
                    ->icon('heroicon-o-chart-bar')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('members_count')
                            ->label('Jumlah Anggota')
                            ->state(fn (Agency $record): int => $record->members()->count())
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
                Tables\Columns\TextColumn::make('agency_code')
                    ->label('Kode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('agency_name')
                    ->label('Nama OPD / Instansi')
                    ->searchable(),
                Tables\Columns\TextColumn::make('payroll_treasurer')
                    ->label('Bendahara Gaji')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('pic_phone_number')
                    ->label('No. HP PIC')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('members_count')
                    ->label('Jumlah Anggota')
                    ->counts('members')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'gray'),
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
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Aktif' => 'Aktif',
                        'Non-Aktif' => 'Non-Aktif',
                    ]),
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
            'index' => Pages\ListAgencies::route('/'),
            'create' => Pages\CreateAgency::route('/create'),
            'view' => Pages\ViewAgency::route('/{record}'),
            'edit' => Pages\EditAgency::route('/{record}/edit'),
        ];
    }
}
