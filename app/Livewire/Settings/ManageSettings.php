<?php

namespace App\Livewire\Settings;

use App\Settings\CooperativeSettings;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class ManageSettings extends Component
{
    use WithFileUploads;

    public string $tab = 'tampilan';

    // --- Tampilan (custom theme) ---
    public ?string $theme_primary = null;

    public ?string $theme_secondary = null;

    // --- Aplikasi ---
    public string $app_name = '';

    public ?string $logo_path = null;

    public ?string $favicon_path = null;

    public $logoUpload = null;

    public $faviconUpload = null;

    // --- Email (SMTP) ---
    public string $mail_host = '';

    public ?int $mail_port = null;

    public ?string $mail_username = null;

    public ?string $mail_password = null;

    public ?string $mail_encryption = null;

    public string $mail_from_address = '';

    public string $mail_from_name = '';

    // --- Koperasi ---
    public ?float $savings_pokok_amount = null;

    public ?float $savings_wajib_belanja_amount = null;

    public ?float $savings_sukarela_min = null;

    public ?float $loan_admin_fee_rate = null;

    public ?float $loan_swp_rate = null;

    public ?float $loan_interest_rate = null;

    public ?float $loan_time_deposit_rate = null;

    public ?float $loan_short_term_max = null;

    // Email tes
    public string $testRecipient = '';

    public function mount(): void
    {
        $general = app(GeneralSettings::class);
        $mail = app(MailSettings::class);
        $coop = app(CooperativeSettings::class);

        $this->theme_primary = $general->theme_primary;
        $this->theme_secondary = $general->theme_secondary;
        $this->app_name = $general->app_name;
        $this->logo_path = $general->logo_path;
        $this->favicon_path = $general->favicon_path;

        $this->mail_host = $mail->mail_host;
        $this->mail_port = $mail->mail_port;
        $this->mail_username = $mail->mail_username;
        $this->mail_password = $mail->mail_password;
        $this->mail_encryption = $mail->mail_encryption;
        $this->mail_from_address = $mail->mail_from_address;
        $this->mail_from_name = $mail->mail_from_name;

        $this->savings_pokok_amount = $coop->savings_pokok_amount;
        $this->savings_wajib_belanja_amount = $coop->savings_wajib_belanja_amount;
        $this->savings_sukarela_min = $coop->savings_sukarela_min;
        $this->loan_admin_fee_rate = $coop->loan_admin_fee_rate;
        $this->loan_swp_rate = $coop->loan_swp_rate;
        $this->loan_interest_rate = $coop->loan_interest_rate;
        $this->loan_time_deposit_rate = $coop->loan_time_deposit_rate;
        $this->loan_short_term_max = $coop->loan_short_term_max;

        $this->testRecipient = (string) (auth()->user()?->email ?? '');
    }

    protected function rules(): array
    {
        $hex = ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'];

        return [
            'theme_primary' => $hex,
            'theme_secondary' => $hex,
            'app_name' => ['required', 'string', 'max:100'],
            'logoUpload' => ['nullable', 'image', 'max:2048'],
            'faviconUpload' => ['nullable', 'image', 'max:1024'],
            'mail_host' => ['required', 'string'],
            'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string'],
            'mail_password' => ['nullable', 'string'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'mail_from_address' => ['required', 'email'],
            'mail_from_name' => ['required', 'string'],
            'savings_pokok_amount' => ['required', 'numeric', 'min:0'],
            'savings_wajib_belanja_amount' => ['required', 'numeric', 'min:0'],
            'savings_sukarela_min' => ['required', 'numeric', 'min:0'],
            'loan_admin_fee_rate' => ['required', 'numeric', 'min:0'],
            'loan_swp_rate' => ['required', 'numeric', 'min:0'],
            'loan_interest_rate' => ['required', 'numeric', 'min:0'],
            'loan_time_deposit_rate' => ['required', 'numeric', 'min:0'],
            'loan_short_term_max' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function resetTheme(): void
    {
        $this->theme_primary = null;
        $this->theme_secondary = null;
    }

    public function save(): void
    {
        $this->validate();

        $general = app(GeneralSettings::class);
        $general->theme_primary = $this->theme_primary ?: null;
        $general->theme_secondary = $this->theme_secondary ?: null;
        $general->app_name = $this->app_name;

        if ($this->logoUpload) {
            $general->logo_path = $this->logoUpload->store('branding', 'public');
        }

        if ($this->faviconUpload) {
            $general->favicon_path = $this->faviconUpload->store('branding', 'public');
        }

        $general->save();
        $this->logo_path = $general->logo_path;
        $this->favicon_path = $general->favicon_path;
        $this->reset('logoUpload', 'faviconUpload');

        $mail = app(MailSettings::class);
        $mail->mail_host = $this->mail_host;
        $mail->mail_port = (int) $this->mail_port;
        $mail->mail_username = $this->mail_username ?: null;
        $mail->mail_password = $this->mail_password ?: null;
        $mail->mail_encryption = $this->mail_encryption ?: null;
        $mail->mail_from_address = $this->mail_from_address;
        $mail->mail_from_name = $this->mail_from_name;
        $mail->save();

        $coop = app(CooperativeSettings::class);
        $coop->savings_pokok_amount = (float) $this->savings_pokok_amount;
        $coop->savings_wajib_belanja_amount = (float) $this->savings_wajib_belanja_amount;
        $coop->savings_sukarela_min = (float) $this->savings_sukarela_min;
        $coop->loan_admin_fee_rate = (float) $this->loan_admin_fee_rate;
        $coop->loan_swp_rate = (float) $this->loan_swp_rate;
        $coop->loan_interest_rate = (float) $this->loan_interest_rate;
        $coop->loan_time_deposit_rate = (float) $this->loan_time_deposit_rate;
        $coop->loan_short_term_max = (float) $this->loan_short_term_max;
        $coop->save();

        $this->dispatch('toast', type: 'success', message: 'Pengaturan tersimpan. Muat ulang halaman untuk menerapkan tema baru ke seluruh aplikasi.');
    }

    public function sendTestEmail(): void
    {
        $this->validateOnly('testRecipient', ['testRecipient' => ['required', 'email']]);

        $scheme = $this->mail_encryption === 'ssl' ? 'smtps' : 'smtp';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.host' => $this->mail_host,
            'mail.mailers.smtp.port' => (int) $this->mail_port,
            'mail.mailers.smtp.username' => $this->mail_username ?: null,
            'mail.mailers.smtp.password' => $this->mail_password ?: null,
            'mail.from.address' => $this->mail_from_address,
            'mail.from.name' => $this->mail_from_name,
        ]);

        try {
            Mail::raw(
                'Ini email tes dari '.$this->mail_from_name.'. Jika diterima, konfigurasi SMTP sudah benar.',
                fn ($message) => $message->to($this->testRecipient)->subject('Tes SMTP — '.$this->app_name)
            );

            $this->dispatch('toast', type: 'success', message: 'Email tes terkirim ke '.$this->testRecipient.'.');
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'danger', message: 'Gagal mengirim: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.settings.manage-settings')
            ->layout('components.layouts.app', ['title' => 'Pengaturan']);
    }
}
