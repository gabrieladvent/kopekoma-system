<?php

namespace App\Filament\Pages;

use App\Settings\CooperativeSettings;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Pengaturan';

    protected static ?string $title = 'Pengaturan';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.manage-settings';

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
        ];
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

    /**
     * Apply the SMTP values currently in the form (even if unsaved) and send a
     * test email so the admin can verify the configuration immediately.
     */
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
