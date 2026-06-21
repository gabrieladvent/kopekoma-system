<?php

namespace App\Filament\Pages;

use App\Models\StoreClient;
use App\Settings\CooperativeSettings;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ManageSettings extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Pengaturan';

    protected static ?string $title = 'Pengaturan';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.manage-settings';

    public const COPY_SECRET_PERMISSION = 'copy_store_client_secret';

    public ?array $data = [];

    public function mount(): void
    {
        $general = app(GeneralSettings::class);

        $mail = app(MailSettings::class);

        $coop = app(CooperativeSettings::class);

        $this->form->fill([
            'app_name' => $general->app_name,
            'logo_path' => $general->logo_path,
            'favicon_path' => $general->favicon_path,

            'mail_host' => $mail->mail_host,
            'mail_port' => $mail->mail_port,
            'mail_username' => $mail->mail_username,
            'mail_password' => $mail->mail_password,
            'mail_encryption' => $mail->mail_encryption,
            'mail_from_address' => $mail->mail_from_address,
            'mail_from_name' => $mail->mail_from_name,

            'savings_pokok_amount' => $coop->savings_pokok_amount,
            'savings_wajib_belanja_amount' => $coop->savings_wajib_belanja_amount,
            'savings_sukarela_min' => $coop->savings_sukarela_min,
            'loan_admin_fee_rate' => $coop->loan_admin_fee_rate,
            'loan_swp_rate' => $coop->loan_swp_rate,
            'loan_interest_rate' => $coop->loan_interest_rate,
            'loan_time_deposit_rate' => $coop->loan_time_deposit_rate,
            'loan_short_term_max' => $coop->loan_short_term_max,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make()
                    ->tabs([
                        Tabs\Tab::make('Aplikasi')
                            ->icon('heroicon-o-computer-desktop')
                            ->schema([
                                Section::make('Identitas Aplikasi')
                                    ->description('Nama, logo, dan favicon aplikasi.')
                                    ->schema([
                                        TextInput::make('app_name')
                                            ->label('Nama Aplikasi')
                                            ->required()
                                            ->maxLength(100),
                                        FileUpload::make('logo_path')
                                            ->label('Logo')
                                            ->image()
                                            ->disk('public')
                                            ->directory('branding')
                                            ->visibility('public')
                                            ->imageEditor()
                                            ->maxSize(2048)
                                            ->helperText('Tampil di header panel admin & halaman login. PNG/SVG, maks 2 MB.'),
                                        FileUpload::make('favicon_path')
                                            ->label('Favicon')
                                            ->image()
                                            ->disk('public')
                                            ->directory('branding')
                                            ->visibility('public')
                                            ->maxSize(1024)
                                            ->helperText('Ikon tab browser. Sebaiknya .png/.ico 32x32 atau .svg.'),
                                    ]),
                            ]),
                        Tabs\Tab::make('Email (SMTP)')
                            ->icon('heroicon-o-envelope')
                            ->schema([
                                Section::make('Server SMTP')
                                    ->description('Konfigurasi server untuk mengirim email keluar.')
                                    ->schema([
                                        TextInput::make('mail_host')
                                            ->label('Host')
                                            ->placeholder('smtp.gmail.com')
                                            ->required(),
                                        TextInput::make('mail_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->placeholder('587')
                                            ->required(),
                                        Select::make('mail_encryption')
                                            ->label('Enkripsi')
                                            ->options([
                                                'tls' => 'TLS (STARTTLS, port 587)',
                                                'ssl' => 'SSL (port 465)',
                                            ])
                                            ->placeholder('Tanpa enkripsi')
                                            ->native(false),
                                        TextInput::make('mail_username')
                                            ->label('Username')
                                            ->autocomplete('off'),
                                        TextInput::make('mail_password')
                                            ->label('Password')
                                            ->password()
                                            ->revealable()
                                            ->autocomplete('new-password')
                                            ->helperText('Untuk Gmail gunakan App Password, bukan password akun.'),
                                    ])->columns(2),
                                Section::make('Pengirim (From)')
                                    ->schema([
                                        TextInput::make('mail_from_address')
                                            ->label('Alamat Email Pengirim')
                                            ->email()
                                            ->required(),
                                        TextInput::make('mail_from_name')
                                            ->label('Nama Pengirim')
                                            ->required(),
                                    ])->columns(2),
                            ]),
                        Tabs\Tab::make('Koperasi')
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Section::make('Simpanan')
                                    ->schema([
                                        TextInput::make('savings_pokok_amount')
                                            ->label('Simpanan Pokok (sekali)')
                                            ->numeric()->prefix('Rp')->required(),
                                        TextInput::make('savings_wajib_belanja_amount')
                                            ->label('Wajib Belanja / Bulan')
                                            ->numeric()->prefix('Rp')->required(),
                                        TextInput::make('savings_sukarela_min')
                                            ->label('Minimal Setor Sukarela')
                                            ->numeric()->prefix('Rp')->required(),
                                    ])->columns(3),
                                Section::make('Pinjaman')
                                    ->description('Persentase ditulis dalam desimal, mis. 0.01 = 1%.')
                                    ->schema([
                                        TextInput::make('loan_admin_fee_rate')
                                            ->label('Biaya Admin')
                                            ->numeric()->step('0.00001')->suffix('× pokok')->required(),
                                        TextInput::make('loan_swp_rate')
                                            ->label('SWP')
                                            ->numeric()->step('0.00001')->suffix('× pokok')->required(),
                                        TextInput::make('loan_interest_rate')
                                            ->label('Jasa')
                                            ->numeric()->step('0.00001')->suffix('× pokok')->required(),
                                        TextInput::make('loan_time_deposit_rate')
                                            ->label('Tabungan Berjangka')
                                            ->numeric()->step('0.00001')->suffix('× pokok')->required(),
                                        TextInput::make('loan_short_term_max')
                                            ->label('Batas Pinjaman Jangka Pendek')
                                            ->numeric()->prefix('Rp')->required(),
                                    ])->columns(2),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testEmail')
                ->label('Kirim Email Tes')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->form([
                    TextInput::make('recipient')
                        ->label('Kirim ke')
                        ->email()
                        ->required()
                        ->default(fn () => auth()->user()?->email),
                ])
                ->action(function (array $data): void {
                    $this->sendTestEmail($data['recipient']);
                }),
            Action::make('createStoreClient')
                ->label('Tambah Klien Toko')
                ->icon('heroicon-o-building-storefront')
                ->modalHeading('Tambah Klien Toko')
                ->modalDescription('Sistem akan membuat Client ID & Secret otomatis. Secret hanya ditampilkan sekali.')
                ->form([
                    TextInput::make('name')
                        ->label('Nama Toko')
                        ->required()
                        ->maxLength(100),
                    Toggle::make('can_refund')
                        ->label('Boleh melakukan refund')
                        ->helperText('Token klien ini akan menyertakan ability shopping:refund.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $secret = Str::random(40);

                    $client = StoreClient::create([
                        'name' => $data['name'],
                        'client_id' => 'store_'.Str::lower(Str::random(20)),
                        'client_secret' => $secret, // di-hash otomatis oleh cast
                        'client_secret_encrypted' => $secret, // di-enkripsi (reversible) untuk copy ulang
                        'is_active' => true,
                        'can_refund' => (bool) ($data['can_refund'] ?? false),
                    ]);

                    $this->notifyCredentials($client->client_id, $secret);
                }),
        ];
    }

    /**
     * Tabel kelola Klien Toko (API). Data tetap di tabel `store_clients` (bukan
     * `settings`) agar token Sanctum tetap berfungsi — UI-nya saja yang di sini.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(StoreClient::query()->latest())
            ->heading('Klien Toko (API Integrasi)')
            ->description('Kredensial aplikasi toko untuk mengakses API pemakaian saldo Wajib Belanja.')
            ->emptyStateHeading('Belum ada klien toko')
            ->emptyStateDescription('Tambah klien lewat tombol "Tambah Klien Toko" di atas.')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('client_id')
                    ->label('Client ID')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Client ID disalin'),
                ToggleColumn::make('is_active')
                    ->label('Aktif'),
                ToggleColumn::make('can_refund')
                    ->label('Boleh Refund'),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->actions([
                TableAction::make('regenerateSecret')
                    ->label('Reset Secret')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Secret lama langsung tak berlaku. Token yang sudah terbit tetap valid sampai kedaluwarsa.')
                    ->action(function (StoreClient $record): void {
                        $secret = Str::random(40);
                        $record->update([
                            'client_secret' => $secret,
                            'client_secret_encrypted' => $secret,
                        ]);

                        $this->notifyCredentials($record->client_id, $secret);
                    }),
                TableAction::make('copyCredential')
                    ->label('Copy Kredensial')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('gray')
                    ->visible(fn (StoreClient $record): bool => (auth()->user()?->can(self::COPY_SECRET_PERMISSION) ?? false)
                        && filled($record->client_secret_encrypted))
                    ->modalHeading('Copy Kredensial Klien')
                    ->modalDescription('Masukkan password akun Anda untuk menampilkan & menyalin kredensial.')
                    ->modalSubmitActionLabel('Verifikasi & Salin')
                    ->form([
                        TextInput::make('password')
                            ->label('Password Anda')
                            ->password()
                            ->required()
                            ->currentPassword()
                            ->validationMessages(['current_password' => 'Password Anda salah.']),
                    ])
                    ->action(function (StoreClient $record): void {
                        $this->revealCredentials($record);
                    }),
                DeleteAction::make(),
            ]);
    }

    private function notifyCredentials(string $clientId, string $secret): void
    {
        Notification::make()
            ->title('Kredensial klien — simpan sekarang')
            ->body(new HtmlString(
                'Secret hanya ditampilkan sekali ini.<br><br>'
                .'<strong>Client ID:</strong><br><code>'.e($clientId).'</code><br><br>'
                .'<strong>Client Secret:</strong><br><code>'.e($secret).'</code>'
            ))
            ->success()
            ->persistent()
            ->send();
    }

    private function revealCredentials(StoreClient $record): void
    {
        $secret = $record->client_secret_encrypted;

        if (blank($secret)) {
            Notification::make()
                ->warning()
                ->title('Secret belum tersedia')
                ->body('Lakukan "Reset Secret" lebih dulu agar kredensial bisa disalin.')
                ->send();

            return;
        }

        $this->dispatch('copy-credential', text: "Client ID: {$record->client_id}\nClient Secret: {$secret}");

        activity()
            ->causedBy(auth()->user())
            ->performedOn($record)
            ->event('reveal_secret')
            ->log("Copy kredensial klien toko {$record->name}");

        Notification::make()
            ->title('Kredensial disalin')
            ->body(new HtmlString(
                'Sudah disalin ke clipboard. Simpan di tempat aman.<br><br>'
                .'<strong>Client ID:</strong><br><code>'.e($record->client_id).'</code><br><br>'
                .'<strong>Client Secret:</strong><br><code>'.e($secret).'</code>'
            ))
            ->success()
            ->persistent()
            ->send();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $general = app(GeneralSettings::class);
        $general->app_name = $data['app_name'];
        $general->logo_path = $data['logo_path'] ?: null;
        $general->favicon_path = $data['favicon_path'] ?: null;
        $general->save();

        $mail = app(MailSettings::class);
        $mail->mail_host = $data['mail_host'];
        $mail->mail_port = (int) $data['mail_port'];
        $mail->mail_username = $data['mail_username'] ?: null;
        $mail->mail_password = $data['mail_password'] ?: null;
        $mail->mail_encryption = $data['mail_encryption'] ?: null;
        $mail->mail_from_address = $data['mail_from_address'];
        $mail->mail_from_name = $data['mail_from_name'];
        $mail->save();

        $coop = app(CooperativeSettings::class);
        $coop->savings_pokok_amount = (float) $data['savings_pokok_amount'];
        $coop->savings_wajib_belanja_amount = (float) $data['savings_wajib_belanja_amount'];
        $coop->savings_sukarela_min = (float) $data['savings_sukarela_min'];
        $coop->loan_admin_fee_rate = (float) $data['loan_admin_fee_rate'];
        $coop->loan_swp_rate = (float) $data['loan_swp_rate'];
        $coop->loan_interest_rate = (float) $data['loan_interest_rate'];
        $coop->loan_time_deposit_rate = (float) $data['loan_time_deposit_rate'];
        $coop->loan_short_term_max = (float) $data['loan_short_term_max'];
        $coop->save();

        Notification::make()
            ->title('Pengaturan tersimpan')
            ->body('Branding & SMTP langsung diterapkan. Muat ulang halaman untuk melihat logo/nama baru.')
            ->success()
            ->send();
    }

    private function sendTestEmail(string $recipient): void
    {
        $data = $this->form->getState();

        $scheme = $data['mail_encryption'] === 'ssl' ? 'smtps' : 'smtp';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.host' => $data['mail_host'],
            'mail.mailers.smtp.port' => (int) $data['mail_port'],
            'mail.mailers.smtp.username' => $data['mail_username'] ?: null,
            'mail.mailers.smtp.password' => $data['mail_password'] ?: null,
            'mail.from.address' => $data['mail_from_address'],
            'mail.from.name' => $data['mail_from_name'],
        ]);

        try {
            Mail::raw(
                'Ini adalah email tes dari '.$data['mail_from_name'].'. Jika Anda menerima pesan ini, konfigurasi SMTP sudah benar.',
                fn ($message) => $message->to($recipient)->subject('Tes SMTP — '.$data['app_name'])
            );

            Notification::make()
                ->title('Email tes terkirim')
                ->body('Cek kotak masuk '.$recipient.' (termasuk folder spam).')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal mengirim email')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
