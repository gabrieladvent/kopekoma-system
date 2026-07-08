<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\FormatsActivity;
use App\Filament\Resources\ActivityResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    use FormatsActivity;

    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Log Aktivitas';

    protected static ?string $modelLabel = 'Log Aktivitas';

    protected static ?string $pluralModelLabel = 'Log Aktivitas';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Aktivitas')
                    ->icon('heroicon-o-bolt')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('event')
                            ->label('Aksi')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::activityEventLabel($state))
                            ->color(fn (?string $state): string => static::activityEventColor($state)),
                        Infolists\Components\TextEntry::make('log_name')
                            ->label('Log')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Deskripsi')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Waktu')
                            ->dateTime('d M Y H:i:s'),
                    ]),
                Infolists\Components\Section::make('Objek & Pelaku')
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('subject_type')
                            ->label('Jenis Data')
                            ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                        Infolists\Components\TextEntry::make('subject_id')
                            ->label('ID Data')
                            ->placeholder('—')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('causer.name')
                            ->label('Pelaku')
                            ->placeholder('Sistem')
                            ->icon('heroicon-o-user-circle'),
                    ]),
                Infolists\Components\Section::make('Perubahan Data')
                    ->icon('heroicon-o-arrows-right-left')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('properties.old')
                            ->label('Sebelum')
                            ->state(fn (Activity $record): array => (array) ($record->properties['old'] ?? []))
                            ->placeholder('—'),
                        Infolists\Components\KeyValueEntry::make('properties.attributes')
                            ->label('Sesudah')
                            ->state(fn (Activity $record): array => (array) ($record->properties['attributes'] ?? []))
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Aksi')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::activityEventLabel($state))
                    ->color(fn (?string $state): string => static::activityEventColor($state)),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Jenis Data')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(40)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Pelaku')
                    ->placeholder('Sistem')
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Aksi')
                    ->options(static::activityEventLabels()),
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Jenis Data')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->pluck('subject_type', 'subject_type')
                        ->map(fn (string $type): string => class_basename($type))
                        ->all()),
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('Pelaku')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\Filter::make('created_at')
                    ->label('Rentang Waktu')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
            'view' => Pages\ViewActivity::route('/{record}'),
        ];
    }
}
