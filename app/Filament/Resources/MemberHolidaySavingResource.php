<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\MemberHolidaySavingResource\Pages;
use App\Filament\Resources\MemberHolidaySavingResource\RelationManagers\DepositsRelationManager;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Services\SavingsBalanceService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MemberHolidaySavingResource extends Resource
{
    protected static ?string $model = MemberHolidaySaving::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Simpanan';

    protected static ?string $navigationLabel = 'Pendaftaran Hari Raya';

    protected static ?string $modelLabel = 'Pendaftaran Hari Raya';

    protected static ?string $pluralModelLabel = 'Pendaftaran Hari Raya';

    protected static ?int $navigationSort = 20;

    public static function normalizeYear(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value) && strlen((string) $value) === 4) {
            return (int) $value;
        }

        return (int) Carbon::parse($value)->year;
    }

    /**
     * Turunkan `period_year` (kunci pengelompokan saldo D1) dari `end_date` =
     * tahun pembagian. Dipanggil Create/Edit page sebelum simpan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withDerivedYear(array $data): array
    {
        $data['period_year'] = self::normalizeYear($data['end_date'] ?? null);

        return $data;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Registrasi Simpanan Hari Raya')
                    ->description('Nominal bulanan yang disepakati anggota per tahun program. Dipakai sebagai nominal terkunci saat setoran Hari Raya.')
                    ->icon('heroicon-o-gift')
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
                            ->rule(fn (Forms\Get $get, ?MemberHolidaySaving $record) => Rule::unique('member_holiday_savings', 'member_id')
                                ->where('period_year', self::normalizeYear($get('end_date')))
                                ->ignore($record?->getKey()))
                            ->validationMessages(['unique' => 'Anggota ini sudah terdaftar pada tahun program tersebut.']),
                        Forms\Components\DatePicker::make('start_date')
                            ->label('Mulai Pengumpulan')
                            ->required()
                            ->default(now()->startOfYear())
                            ->helperText('Tanggal awal periode pengumpulan Hari Raya.'),
                        Forms\Components\DatePicker::make('end_date')
                            ->label('Akhir Pengumpulan')
                            ->required()
                            ->default(now()->endOfYear())
                            ->afterOrEqual('start_date')
                            ->helperText('Tanggal terakhir setoran sebelum dibagikan. Tahunnya = tahun program.')
                            ->validationMessages(['after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal mulai.']),
                        MoneyInput::make('monthly_amount')
                            ->label('Nominal Bulanan')
                            ->required()
                            ->minValue(1)
                            ->helperText('Nominal per setoran untuk anggota ini di tahun tersebut.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->helperText('Hanya registrasi aktif yang bisa dipakai saat setoran.'),
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
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('member.full_name')
                            ->label('Anggota')
                            ->icon('heroicon-o-user'),
                        Infolists\Components\TextEntry::make('member.member_number')
                            ->label('No. Anggota')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('period_year')
                            ->label('Tahun Program')
                            ->badge()
                            ->color('warning'),
                        Infolists\Components\TextEntry::make('collection_range')
                            ->label('Periode Pengumpulan')
                            ->icon('heroicon-o-calendar-days')
                            ->state(fn (MemberHolidaySaving $record): string => trim(
                                ($record->start_date?->translatedFormat('d M Y') ?? '—').
                                ' s/d '.
                                ($record->end_date?->translatedFormat('d M Y') ?? '—')
                            )),
                        Infolists\Components\TextEntry::make('monthly_amount')
                            ->label('Nominal Bulanan')
                            ->money('IDR')
                            ->weight('bold')
                            ->color('success'),
                        Infolists\Components\TextEntry::make('balance')
                            ->label('Saldo Terkumpul')
                            ->icon('heroicon-o-wallet')
                            ->money('IDR')
                            ->weight('bold')
                            ->color('primary')
                            ->state(fn (MemberHolidaySaving $record): string => app(SavingsBalanceService::class)
                                ->holidayBalance($record->member, $record->period_year))
                            ->helperText('Total setoran dikurangi pencairan untuk tahun program ini.'),
                        Infolists\Components\IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
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
            ->defaultSort('period_year', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('member.full_name')
                    ->label('Anggota')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('member.member_number')
                    ->label('No. Anggota')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('period_year')
                    ->label('Tahun')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('Akhir')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('monthly_amount')
                    ->label('Nominal Bulanan')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period_year')
                    ->label('Tahun')
                    ->options(fn (): array => MemberHolidaySaving::query()
                        ->distinct()
                        ->orderByDesc('period_year')
                        ->pluck('period_year', 'period_year')
                        ->all()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DepositsRelationManager::class,
            AuditTrailRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemberHolidaySavings::route('/'),
            'create' => Pages\CreateMemberHolidaySaving::route('/create'),
            'view' => Pages\ViewMemberHolidaySaving::route('/{record}'),
            'edit' => Pages\EditMemberHolidaySaving::route('/{record}/edit'),
        ];
    }
}
