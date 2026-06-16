<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\Pages\CreateMember;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Grade;
use App\Models\Member;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationLabel = 'Anggota';

    protected static ?string $modelLabel = 'Anggota';

    protected static ?string $pluralModelLabel = 'Anggota';

    /**
     * Roles allowed to perform Pengurus-and-above actions per the D4 access
     * matrix: override mandatory savings, import, and export (PDF card / Excel).
     * super_admin bypasses Shield gates but is listed explicitly because these
     * are role checks, not policy gates.
     */
    private const ELEVATED_ROLES = ['super_admin', 'pengurus'];

    public static function canOverrideMandatorySavings(): bool
    {
        return auth()->user()?->hasAnyRole(self::ELEVATED_ROLES) ?? false;
    }

    public static function canImportMembers(): bool
    {
        return auth()->user()?->hasAnyRole(self::ELEVATED_ROLES) ?? false;
    }

    public static function canExportMembers(): bool
    {
        return auth()->user()?->hasAnyRole(self::ELEVATED_ROLES) ?? false;
    }

    public static function normalizePhone(?string $state): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $state);

        $digits = preg_replace('/^62/', '', (string) $digits);

        $digits = ltrim((string) $digits, '0');

        return $digits === '' ? null : '+62'.$digits;
    }

    public static function localPhone(?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $state);

        $digits = preg_replace('/^62/', '', (string) $digits);

        return ltrim((string) $digits, '0') ?: null;
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'Aktif' => 'success',
            'Non-Aktif' => 'gray',
            'Keluar' => 'warning',
            'Meninggal' => 'danger',
            default => 'gray',
        };
    }

    public static function printCard(Member $member): StreamedResponse
    {
        $pdf = Pdf::loadView('pdf.member-card', ['member' => $member->loadMissing(['agency', 'grade'])]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'kartu-anggota-'.$member->member_number.'.pdf',
        );
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identitas Anggota')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('member_number')
                            ->label('Nomor Anggota')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(fn (): string => Member::generateMemberNumber())
                            ->helperText('Digenerate otomatis & tidak dapat diubah (format KM-YYYY-NNNN).'),
                        Forms\Components\TextInput::make('full_name')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Nama sesuai KTP'),
                        Forms\Components\TextInput::make('nik')
                            ->label('NIK')
                            ->required()
                            ->length(16)
                            ->numeric()
                            ->unique(ignoreRecord: true)
                            ->placeholder('16 digit')
                            ->helperText('Nomor Induk Kependudukan, 16 digit, unik.')
                            ->validationMessages([
                                'min' => 'NIK harus terdiri dari tepat 16 angka.',
                                'max' => 'NIK harus terdiri dari tepat 16 angka.',
                            ]),
                        Forms\Components\TextInput::make('nip')
                            ->label('NIP')
                            ->maxLength(25)
                            ->required(fn (Get $get): bool => $get('employment_status') === 'ASN')
                            ->placeholder('Wajib untuk ASN')
                            ->helperText('Nomor Induk Pegawai. Wajib untuk ASN, opsional untuk Honorer.'),
                        Forms\Components\TextInput::make('birth_place')
                            ->label('Tempat Lahir')
                            ->required()
                            ->maxLength(50),
                        Forms\Components\DatePicker::make('birth_date')
                            ->label('Tanggal Lahir')
                            ->required()
                            ->maxDate(now()),
                        Forms\Components\Select::make('gender')
                            ->label('Jenis Kelamin')
                            ->options([
                                'L' => 'Laki-laki',
                                'P' => 'Perempuan',
                            ])
                            ->required(),
                    ]),
                Forms\Components\Section::make('Instansi & Golongan')
                    ->icon('heroicon-o-building-office-2')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('agency_id')
                            ->label('OPD / Instansi')
                            ->relationship('agency', 'agency_name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Instansi tempat anggota bekerja.'),
                        Forms\Components\Select::make('grade_id')
                            ->label('Golongan')
                            ->relationship('grade', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Grade $record): string => "{$record->code} — {$record->name}")
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, $livewire): void {
                                if ($livewire instanceof CreateMember) {
                                    $set('mandatory_savings_amount', Grade::find($state)?->mandatory_savings_amount);
                                }
                            })
                            ->helperText('Golongan menentukan default nominal simpanan wajib (snapshot).'),
                        Forms\Components\Select::make('employment_status')
                            ->label('Status Kepegawaian')
                            ->options([
                                'ASN' => 'ASN',
                                'Honorer' => 'Honorer',
                            ])
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('position')
                            ->label('Jabatan')
                            ->maxLength(100)
                            ->placeholder('Opsional'),
                    ]),
                Forms\Components\Section::make('Keuangan')
                    ->icon('heroicon-o-banknotes')
                    ->columns(2)
                    ->schema([
                        MoneyInput::make('mandatory_savings_amount')
                            ->label('Simpanan Wajib / Bulan')
                            ->required()
                            ->dehydrated()
                            ->disabled(fn (): bool => ! static::canOverrideMandatorySavings())
                            ->helperText(
                                static::canOverrideMandatorySavings()
                                    ? 'Default dari golongan; boleh di-override.'
                                    : 'Default dari golongan. Hanya Pengurus ke atas yang dapat override.'
                            ),
                        Forms\Components\TextInput::make('payroll_account_number')
                            ->label('No. Rekening Gaji')
                            ->required()
                            ->maxLength(30)
                            ->helperText('Rekening tujuan potong gaji.'),
                        Forms\Components\TextInput::make('bank_name')
                            ->label('Nama Bank')
                            ->maxLength(50)
                            ->placeholder('Opsional'),
                    ]),
                Forms\Components\Section::make('Kontak & Alamat')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('phone_number')
                            ->label('No. HP')
                            ->tel()
                            ->prefix('+62')
                            ->required()
                            ->maxLength(15)
                            ->placeholder('81234567890')
                            ->helperText('Tanpa angka 0 di depan. Disimpan dengan awalan +62.')
                            ->formatStateUsing(fn (?string $state): ?string => static::localPhone($state))
                            ->dehydrateStateUsing(fn (?string $state): ?string => static::normalizePhone($state)),
                        Forms\Components\Textarea::make('address')
                            ->label('Alamat')
                            ->required()
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Keanggotaan')
                    ->icon('heroicon-o-calendar-days')
                    ->columns(3)
                    ->schema([
                        Forms\Components\DatePicker::make('join_date')
                            ->label('Tanggal Bergabung')
                            ->required()
                            ->default(now()),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'Aktif' => 'Aktif',
                                'Non-Aktif' => 'Non-Aktif',
                                'Keluar' => 'Keluar',
                                'Meninggal' => 'Meninggal',
                            ])
                            ->default('Aktif')
                            ->required()
                            ->live(),
                        Forms\Components\DatePicker::make('exit_date')
                            ->label('Tanggal Keluar')
                            ->required(fn (Get $get): bool => in_array($get('status'), ['Keluar', 'Meninggal'], true))
                            ->helperText('Wajib bila status Keluar / Meninggal.'),
                    ]),
                Forms\Components\Section::make('Ahli Waris')
                    ->icon('heroicon-o-user-group')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('heir_name')
                            ->label('Nama Ahli Waris')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Select::make('heir_relationship')
                            ->label('Hubungan')
                            ->options(Member::HEIR_RELATIONSHIPS)
                            ->required()
                            ->native(false)
                            ->rule(Rule::in(array_keys(Member::HEIR_RELATIONSHIPS)))
                            ->placeholder('Pilih hubungan')
                            ->helperText('Hubungan ahli waris dengan anggota.'),
                        Forms\Components\TextInput::make('heir_phone_number')
                            ->label('No. HP Ahli Waris')
                            ->tel()
                            ->prefix('+62')
                            ->required()
                            ->maxLength(15)
                            ->placeholder('81234567890')
                            ->helperText('Tanpa angka 0 di depan. Disimpan dengan awalan +62.')
                            ->formatStateUsing(fn (?string $state): ?string => static::localPhone($state))
                            ->dehydrateStateUsing(fn (?string $state): ?string => static::normalizePhone($state)),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\TextEntry::make('full_name')
                            ->hiddenLabel()
                            ->weight('bold')
                            ->size('xl'),
                        Infolists\Components\TextEntry::make('headline')
                            ->hiddenLabel()
                            ->color('gray')
                            ->icon('heroicon-m-briefcase')
                            ->state(fn (Member $record): string => trim(
                                ($record->position ? $record->position.' · ' : '').
                                ($record->agency?->agency_name ?? ''),
                                ' ·'
                            ) ?: '—'),
                        Infolists\Components\Grid::make(['default' => 2, 'sm' => 4])
                            ->schema([
                                Infolists\Components\TextEntry::make('member_number')
                                    ->label('Nomor Anggota')
                                    ->badge()
                                    ->color('primary')
                                    ->icon('heroicon-m-identification')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (string $state): string => static::statusColor($state)),
                                Infolists\Components\TextEntry::make('grade.code')
                                    ->label('Golongan')
                                    ->badge()
                                    ->color('info')
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('employment_status')
                                    ->label('Kepegawaian')
                                    ->badge()
                                    ->color('gray'),
                            ]),
                    ]),

                Infolists\Components\Grid::make(3)
                    ->schema([
                        Infolists\Components\Group::make([
                            Infolists\Components\Section::make('Data Pribadi')
                                ->icon('heroicon-o-user')
                                ->columns(2)
                                ->schema([
                                    Infolists\Components\TextEntry::make('nik')
                                        ->label('NIK')
                                        ->icon('heroicon-o-identification')
                                        ->copyable(),
                                    Infolists\Components\TextEntry::make('nip')
                                        ->label('NIP')
                                        ->placeholder('—'),
                                    Infolists\Components\TextEntry::make('birth')
                                        ->label('Tempat, Tanggal Lahir')
                                        ->state(fn (Member $record): string => trim(
                                            ($record->birth_place ?? '').', '.
                                            ($record->birth_date?->translatedFormat('d F Y') ?? '-'),
                                            ', '
                                        )),
                                    Infolists\Components\TextEntry::make('gender')
                                        ->label('Jenis Kelamin')
                                        ->badge()
                                        ->color(fn (string $state): string => $state === 'L' ? 'info' : 'warning')
                                        ->formatStateUsing(fn (string $state): string => $state === 'L' ? 'Laki-laki' : 'Perempuan'),
                                ]),
                            Infolists\Components\Section::make('Kepegawaian')
                                ->icon('heroicon-o-briefcase')
                                ->columns(2)
                                ->schema([
                                    Infolists\Components\TextEntry::make('agency.agency_name')
                                        ->label('OPD / Instansi')
                                        ->icon('heroicon-o-building-office-2')
                                        ->placeholder('—'),
                                    Infolists\Components\TextEntry::make('grade.name')
                                        ->label('Golongan')
                                        ->placeholder('—'),
                                    Infolists\Components\TextEntry::make('position')
                                        ->label('Jabatan')
                                        ->placeholder('—'),
                                    Infolists\Components\TextEntry::make('employment_status')
                                        ->label('Status Kepegawaian')
                                        ->badge()
                                        ->color('gray'),
                                ]),
                            Infolists\Components\Section::make('Kontak & Alamat')
                                ->icon('heroicon-o-map-pin')
                                ->columns(2)
                                ->schema([
                                    Infolists\Components\TextEntry::make('phone_number')
                                        ->label('No. HP')
                                        ->icon('heroicon-o-phone')
                                        ->copyable(),
                                    Infolists\Components\TextEntry::make('payroll_account_number')
                                        ->label('No. Rekening Gaji')
                                        ->icon('heroicon-o-credit-card')
                                        ->copyable(),
                                    Infolists\Components\TextEntry::make('bank_name')
                                        ->label('Nama Bank')
                                        ->placeholder('—'),
                                    Infolists\Components\TextEntry::make('address')
                                        ->label('Alamat')
                                        ->columnSpanFull()
                                        ->placeholder('—'),
                                ]),
                        ])->columnSpan(2),

                        Infolists\Components\Group::make([
                            Infolists\Components\Section::make('Simpanan Wajib')
                                ->icon('heroicon-o-banknotes')
                                ->schema([
                                    Infolists\Components\TextEntry::make('mandatory_savings_amount')
                                        ->hiddenLabel()
                                        ->money('IDR')
                                        ->weight('bold')
                                        ->size('lg')
                                        ->color('success')
                                        ->helperText('Per bulan (snapshot).'),
                                ]),
                            Infolists\Components\Section::make('Ahli Waris')
                                ->icon('heroicon-o-user-group')
                                ->description('Kontak darurat / ahli waris')
                                ->schema([
                                    Infolists\Components\TextEntry::make('heir_name')
                                        ->label('Nama')
                                        ->icon('heroicon-o-user'),
                                    Infolists\Components\TextEntry::make('heir_relationship')
                                        ->label('Hubungan')
                                        ->badge()
                                        ->color('gray'),
                                    Infolists\Components\TextEntry::make('heir_phone_number')
                                        ->label('No. HP')
                                        ->icon('heroicon-o-phone')
                                        ->copyable(),
                                ]),
                            Infolists\Components\Section::make('Keanggotaan')
                                ->icon('heroicon-o-calendar-days')
                                ->schema([
                                    Infolists\Components\TextEntry::make('join_date')
                                        ->label('Bergabung')
                                        ->icon('heroicon-o-arrow-right-on-rectangle')
                                        ->date('d M Y'),
                                    Infolists\Components\TextEntry::make('exit_date')
                                        ->label('Keluar')
                                        ->icon('heroicon-o-arrow-left-on-rectangle')
                                        ->date('d M Y')
                                        ->placeholder('—'),
                                ]),
                        ])->columnSpan(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member_number')
                    ->label('No. Anggota')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nik')
                    ->label('NIK')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('nip')
                    ->label('NIP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('agency.agency_name')
                    ->label('OPD / Instansi')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('grade.code')
                    ->label('Golongan')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('mandatory_savings_amount')
                    ->label('Simpanan Wajib')
                    ->money('IDR')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => static::statusColor($state)),
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
                Tables\Filters\SelectFilter::make('agency_id')
                    ->label('OPD / Instansi')
                    ->relationship('agency', 'agency_name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('grade_id')
                    ->label('Golongan')
                    ->relationship('grade', 'name')
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Aktif' => 'Aktif',
                        'Non-Aktif' => 'Non-Aktif',
                        'Keluar' => 'Keluar',
                        'Meninggal' => 'Meninggal',
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('cetakKartu')
                        ->label('Cetak Kartu')
                        ->icon('heroicon-o-identification')
                        ->visible(fn (): bool => static::canExportMembers())
                        ->action(fn (Member $record): StreamedResponse => static::printCard($record)),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            MemberResource\RelationManagers\DocumentsRelationManager::class,
            AuditTrailRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'view' => Pages\ViewMember::route('/{record}'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }
}
