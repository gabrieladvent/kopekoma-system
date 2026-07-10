<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberSavingsBalanceResource\Pages;
use App\Models\Member;
use App\Services\SavingsBalanceService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MemberSavingsBalanceResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'Utama';

    protected static ?string $navigationLabel = 'Saldo Anggota';

    protected static ?string $modelLabel = 'Saldo Anggota';

    protected static ?string $pluralModelLabel = 'Saldo Anggota';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Saldo computed-on-read, di-memo per request agar tiap kolom tak memanggil
     * ulang service untuk member yang sama (allBalances = ~3 query / anggota).
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $memo = [];

    /**
     * @return array{pokok:string,wajib:string,sukarela:string,wajib_belanja:string,hari_raya:array<int,string>}
     */
    protected static function balances(Member $member): array
    {
        return static::$memo[(string) $member->getKey()] ??= app(SavingsBalanceService::class)->allBalances($member);
    }

    protected static function holidayTotal(Member $member): string
    {
        return array_reduce(
            static::balances($member)['hari_raya'],
            fn (string $carry, string $balance): string => bcadd($carry, $balance, 2),
            '0',
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('member_number')
            ->columns([
                Tables\Columns\TextColumn::make('member_number')
                    ->label('No. Anggota')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('agency.agency_name')
                    ->label('OPD')
                    ->toggleable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('grade.code')
                    ->label('Gol.')
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('saldo_pokok')
                    ->label('Pokok')
                    ->money('IDR')
                    ->alignEnd()
                    ->state(fn (Member $record): string => static::balances($record)['pokok']),
                Tables\Columns\TextColumn::make('saldo_wajib')
                    ->label('Wajib')
                    ->money('IDR')
                    ->alignEnd()
                    ->state(fn (Member $record): string => static::balances($record)['wajib']),
                Tables\Columns\TextColumn::make('saldo_sukarela')
                    ->label('Sukarela')
                    ->money('IDR')
                    ->alignEnd()
                    ->state(fn (Member $record): string => static::balances($record)['sukarela']),
                Tables\Columns\TextColumn::make('saldo_hari_raya')
                    ->label('Hari Raya')
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable()
                    ->state(fn (Member $record): string => static::holidayTotal($record)),
                Tables\Columns\TextColumn::make('saldo_wajib_belanja')
                    ->label('Wajib Belanja')
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable()
                    ->state(fn (Member $record): string => static::balances($record)['wajib_belanja']),
                Tables\Columns\TextColumn::make('saldo_total')
                    ->label('Total')
                    ->money('IDR')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success')
                    ->state(fn (Member $record): string => app(SavingsBalanceService::class)->totalBalance($record)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agency')
                    ->label('OPD')
                    ->relationship('agency', 'agency_name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('grade')
                    ->label('Golongan')
                    ->relationship('grade', 'code'),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Aktif' => 'Aktif',
                        'Non-Aktif' => 'Non-Aktif',
                        'Keluar' => 'Keluar',
                        'Meninggal' => 'Meninggal',
                    ])
                    ->default('Aktif'),
            ])
            ->actions([
                Tables\Actions\Action::make('lihat')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Member $record): string => MemberResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([]);
    }

    public static function canViewForRecord(Model $record, string $pageClass): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemberSavingsBalances::route('/'),
        ];
    }
}
