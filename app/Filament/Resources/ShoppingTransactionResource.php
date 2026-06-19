<?php

namespace App\Filament\Resources;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Filament\Resources\ShoppingTransactionResource\Pages;
use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ShoppingTransactionResource extends Resource
{
    protected static ?string $model = ShoppingTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Simpanan';

    protected static ?string $navigationLabel = 'Belanja Toko';

    protected static ?string $modelLabel = 'Belanja Toko';

    protected static ?string $pluralModelLabel = 'Belanja Toko';

    protected static ?int $navigationSort = 40;

    /**
     * Saldo Wajib Belanja anggota (deposits − pemakaian). Null bila anggota
     * belum dipilih/ada. Dipakai untuk hint form & validasi nominal.
     */
    public static function shoppingBalance(mixed $memberId): ?string
    {
        if (blank($memberId)) {
            return null;
        }

        $member = Member::find($memberId);

        return $member === null
            ? null
            : app(SavingsBalanceService::class)->shoppingBalance($member);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Idempotency (D4/D6): satu render = satu key, anti double-submit.
                Forms\Components\Hidden::make('idempotency_key')
                    ->default(fn (): string => (string) Str::uuid()),
                Forms\Components\Section::make('Pemakaian Saldo Wajib Belanja')
                    ->icon('heroicon-o-shopping-cart')
                    ->description('Mencatat pemakaian saldo Wajib Belanja anggota (mengurangi saldo). Dana tidak dapat diuangkan.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Anggota')
                            ->relationship('member', 'full_name')
                            ->getOptionLabelFromRecordUsing(fn (Member $record): string => "{$record->member_number} — {$record->full_name}")
                            ->searchable(['member_number', 'full_name'])
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('amount', null)),
                        MoneyInput::make('amount')
                            ->label('Nominal Pemakaian')
                            ->required()
                            ->minValue(1)
                            ->helperText(function (Get $get): string {
                                $balance = self::shoppingBalance($get('member_id'));

                                return $balance === null
                                    ? 'Pilih anggota untuk melihat saldo Wajib Belanja.'
                                    : 'Saldo Wajib Belanja: Rp '.number_format((float) $balance, 0, ',', '.');
                            })
                            // Pemakaian tak boleh melebihi saldo Wajib Belanja (D6).
                            ->rule(fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                                $balance = self::shoppingBalance($get('member_id'));

                                if ($balance !== null && bccomp((string) $value, $balance, 2) > 0) {
                                    $fail('Nominal melebihi saldo Wajib Belanja (Rp '.number_format((float) $balance, 0, ',', '.').').');
                                }
                            }),
                        Forms\Components\DatePicker::make('transaction_date')
                            ->label('Tanggal Pemakaian')
                            ->required()
                            ->default(now())
                            ->maxDate(now()),
                        Forms\Components\TextInput::make('reference_number')
                            ->label('No. Referensi')
                            ->maxLength(50)
                            ->placeholder('Opsional')
                            ->helperText('Nomor nota/bukti belanja bila ada.'),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->placeholder('Opsional'),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\Grid::make(['default' => 2, 'sm' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('transaction_number')
                                    ->label('No. Transaksi')
                                    ->badge()
                                    ->color('primary')
                                    ->icon('heroicon-m-hashtag')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('amount')
                                    ->label('Nominal')
                                    ->money('IDR')
                                    ->weight('bold')
                                    ->color('danger'),
                                Infolists\Components\TextEntry::make('transaction_date')
                                    ->label('Tanggal')
                                    ->date('d M Y'),
                                Infolists\Components\IconEntry::make('is_reversal')
                                    ->label('Reversal')
                                    ->boolean(),
                            ]),
                    ]),
                Infolists\Components\Section::make('Detail')
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('member.full_name')
                            ->label('Anggota')
                            ->icon('heroicon-o-user'),
                        Infolists\Components\TextEntry::make('member.member_number')
                            ->label('No. Anggota')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('reference_number')
                            ->label('No. Referensi')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('recordedBy.name')
                            ->label('Dicatat Oleh')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Catatan')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_number')
                    ->label('No. Transaksi')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('member.full_name')
                    ->label('Anggota')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('No. Referensi')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_reversal')
                    ->label('Reversal')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_reversal')
                    ->label('Reversal'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('reverse')
                        ->label('Reversal')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->visible(fn (ShoppingTransaction $record): bool => static::canReverse($record))
                        ->form(static::reverseFormSchema())
                        ->requiresConfirmation()
                        ->modalHeading('Reversal Pemakaian Belanja')
                        ->modalDescription('Membuat transaksi-lawan; saldo Wajib Belanja kembali. Baris asli tidak dihapus.')
                        ->action(fn (ShoppingTransaction $record, array $data) => static::performReversal($record, $data)),
                ]),
            ]);
    }

    /**
     * Reversal hanya atas baris asli (bukan reversal) + ber-permission (D7).
     */
    public static function canReverse(ShoppingTransaction $record): bool
    {
        return ! $record->is_reversal
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    /**
     * @return array<int, Component>
     */
    public static function reverseFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('reason')
                ->label('Alasan Reversal')
                ->required()
                ->minLength(5)
                ->maxLength(65535)
                ->helperText('Wajib, minimal 5 karakter. Akan tercatat di log audit.'),
        ];
    }

    public static function performReversal(ShoppingTransaction $record, array $data): void
    {
        try {
            app(ReverseTransaction::class)($record, (string) $data['reason']);

            Notification::make()
                ->success()
                ->title('Reversal berhasil')
                ->body('Transaksi-lawan telah dibuat dan saldo Wajib Belanja tersesuaikan.')
                ->send();
        } catch (CannotReverseTransaction $e) {
            Notification::make()
                ->danger()
                ->title('Reversal ditolak')
                ->body($e->getMessage())
                ->send();
        }
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
            'index' => Pages\ListShoppingTransactions::route('/'),
            'create' => Pages\CreateShoppingTransaction::route('/create'),
            'view' => Pages\ViewShoppingTransaction::route('/{record}'),
        ];
    }
}
