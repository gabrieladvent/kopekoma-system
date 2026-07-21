<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'User';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    protected static function isSelf(?Model $record): bool
    {
        return $record !== null && auth()->id() === $record->getKey();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identitas')
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Nama lengkap'),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->placeholder('nama@kopekoma.test'),
                    ]),
                Forms\Components\Section::make('Keamanan')
                    ->icon('heroicon-o-lock-closed')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->confirmed()
                            ->maxLength(255)
                            ->helperText('Kosongkan bila tidak ingin mengubah password.'),
                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Konfirmasi Password')
                            ->password()
                            ->revealable()
                            ->requiredWith('password')
                            ->dehydrated(false)
                            ->maxLength(255),
                    ]),
                Forms\Components\Section::make('Akses & Status')
                    ->icon('heroicon-o-shield-check')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('roles')
                            ->label('Role')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('Satu user boleh memiliki lebih dari satu role.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->disabled(fn (?Model $record): bool => static::isSelf($record))
                            ->dehydrated(true)
                            ->helperText('User nonaktif tidak bisa login ke panel.'),
                        Forms\Components\Toggle::make('email_verified_at')
                            ->label('Email terverifikasi')
                            ->formatStateUsing(fn (mixed $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (mixed $state): mixed => $state ? now() : null)
                            ->helperText('Tandai bila email sudah terverifikasi.'),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Identitas')
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Nama')
                            ->weight('bold')
                            ->size('lg'),
                        Infolists\Components\TextEntry::make('email')
                            ->label('Email')
                            ->icon('heroicon-o-envelope')
                            ->copyable(),
                    ]),
                Infolists\Components\Section::make('Akses & Role')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Infolists\Components\TextEntry::make('roles.name')
                            ->label('Role')
                            ->badge()
                            ->color('primary')
                            ->placeholder('Tidak ada role'),
                    ]),
                Infolists\Components\Section::make('Status')
                    ->icon('heroicon-o-information-circle')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        Infolists\Components\IconEntry::make('email_verified_at')
                            ->label('Email terverifikasi')
                            ->boolean(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime('d M Y H:i'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->color('primary')
                    ->separator(','),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Terverifikasi')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label('Verifikasi Email')
                    ->nullable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn (Model $record): bool => static::isSelf($record)),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
