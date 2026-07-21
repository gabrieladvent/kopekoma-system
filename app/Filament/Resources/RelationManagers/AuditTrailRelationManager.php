<?php

namespace App\Filament\Resources\RelationManagers;

use App\Filament\Concerns\FormatsActivity;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class AuditTrailRelationManager extends RelationManager
{
    use FormatsActivity;

    protected static string $relationship = 'activities';

    protected static ?string $title = 'Audit Trail';

    protected static ?string $icon = 'heroicon-o-clipboard-document-list';

    /**
     * Column names to hide for a specific resource (override in a subclass).
     *
     * @var array<int, string>
     */
    protected static array $hiddenColumns = [];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('created_at', 'desc')
            ->columns(static::visibleColumns())
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Aksi')
                    ->options(static::activityEventLabels()),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalWidth(MaxWidth::FiveExtraLarge),
            ])
            ->bulkActions([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Aktivitas')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('event')
                            ->label('Aksi')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::activityEventLabel($state))
                            ->color(fn (?string $state): string => static::activityEventColor($state)),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Waktu')
                            ->dateTime('d M Y H:i:s'),
                        Infolists\Components\TextEntry::make('causer.name')
                            ->label('Diinput oleh')
                            ->placeholder('Sistem'),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Deskripsi')
                            ->columnSpanFull(),
                    ]),
                Infolists\Components\Section::make('Perubahan Data')
                    ->columns(1)
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('properties.attributes')
                            ->label('Sesudah')
                            ->keyLabel('Kolom')
                            ->valueLabel('Nilai')
                            ->state(fn (Activity $record): array => (array) ($record->properties['attributes'] ?? []))
                            ->placeholder('—'),
                        Infolists\Components\KeyValueEntry::make('properties.old')
                            ->label('Sebelum')
                            ->keyLabel('Kolom')
                            ->valueLabel('Nilai')
                            ->state(fn (Activity $record): array => (array) ($record->properties['old'] ?? []))
                            ->visible(fn (Activity $record): bool => filled($record->properties['old'] ?? null))
                            ->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * Build the column set, minus any columns hidden by a subclass.
     *
     * @return array<int, Tables\Columns\Column>
     */
    protected static function visibleColumns(): array
    {
        $columns = [
            'created_at' => Tables\Columns\TextColumn::make('created_at')
                ->label('Waktu')
                ->dateTime('d M Y H:i')
                ->sortable(),
            'event' => Tables\Columns\TextColumn::make('event')
                ->label('Aksi')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => static::activityEventLabel($state))
                ->color(fn (?string $state): string => static::activityEventColor($state)),
            'description' => Tables\Columns\TextColumn::make('description')
                ->label('Deskripsi')
                ->limit(40)
                ->toggleable(),
            'causer.name' => Tables\Columns\TextColumn::make('causer.name')
                ->label('Diinput oleh')
                ->placeholder('Sistem')
                ->searchable(),
        ];

        foreach (static::$hiddenColumns as $name) {
            unset($columns[$name]);
        }

        return array_values($columns);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return method_exists($ownerRecord, static::$relationship);
    }
}
