<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanBlacklistResource\Pages;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\LoanBlacklist;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LoanBlacklistResource extends Resource
{
    protected static ?string $model = LoanBlacklist::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Pinjaman';

    protected static ?string $navigationLabel = 'Blacklist Pinjaman';

    protected static ?string $modelLabel = 'Blacklist';

    protected static ?string $pluralModelLabel = 'Blacklist Pinjaman';

    protected static ?int $navigationSort = 30;

    public static function release(LoanBlacklist $record): void
    {
        if (! $record->is_active) {
            return;
        }

        $record->update([
            'is_active' => false,
            'released_at' => now()->toDateString(),
        ]);

        Notification::make()
            ->success()
            ->title('Blacklist dilepas')
            ->body('Anggota kembali dapat mengajukan pinjaman.')
            ->send();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tandai Blacklist')
                ->icon('heroicon-o-no-symbol')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('member_id')
                        ->label('Anggota')
                        ->relationship('member', 'full_name')
                        ->getOptionLabelFromRecordUsing(fn (Member $record): string => "{$record->member_number} — {$record->full_name}")
                        ->searchable(['member_number', 'full_name'])
                        ->preload()
                        ->required(),
                    Forms\Components\DatePicker::make('blacklisted_at')
                        ->label('Tanggal Blacklist')
                        ->required()
                        ->default(now()),
                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan')
                        ->required()
                        ->minLength(5)
                        ->maxLength(65535)
                        ->columnSpanFull()
                        ->helperText('Wajib, minimal 5 karakter.'),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(2)->schema([
                Infolists\Components\TextEntry::make('member.full_name')->label('Anggota'),
                Infolists\Components\TextEntry::make('member.member_number')->label('No. Anggota')->copyable(),
                Infolists\Components\IconEntry::make('is_active')->label('Aktif')->boolean(),
                Infolists\Components\TextEntry::make('blacklisted_at')->label('Tgl Blacklist')->date('d M Y'),
                Infolists\Components\TextEntry::make('released_at')->label('Tgl Dilepas')->date('d M Y')->placeholder('—'),
                Infolists\Components\TextEntry::make('recordedBy.name')->label('Dicatat Oleh')->placeholder('—'),
                Infolists\Components\TextEntry::make('reason')->label('Alasan')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('member.full_name')->label('Anggota')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('reason')->label('Alasan')->limit(40)->wrap(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
                Tables\Columns\TextColumn::make('blacklisted_at')->label('Tgl Blacklist')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('released_at')->label('Dilepas')->date('d M Y')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('release')
                    ->label('Lepas')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->visible(fn (LoanBlacklist $record): bool => $record->is_active
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Lepas Blacklist')
                    ->modalDescription('Anggota akan kembali dapat mengajukan pinjaman.')
                    ->action(fn (LoanBlacklist $record) => static::release($record)),
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
            'index' => Pages\ListLoanBlacklists::route('/'),
            'create' => Pages\CreateLoanBlacklist::route('/create'),
            'view' => Pages\ViewLoanBlacklist::route('/{record}'),
        ];
    }
}
