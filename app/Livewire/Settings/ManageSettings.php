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

    /** Path gambar latar login yang sudah tersimpan (urutan = urutan tampil). */
    public array $login_background_images = [];

    /** Upload gambar latar login baru (ditambahkan saat Simpan). */
    public $loginImageUploads = [];

    public const MAX_LOGIN_IMAGES = 6;

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

    // --- Identitas Koperasi (kop laporan PDF) ---
    public ?string $cooperative_address = null;

    public ?string $cooperative_city = null;

    public ?string $cooperative_phone = null;

    public ?string $signatory_name = null;

    public ?string $signatory_position = null;

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
        $this->login_background_images = array_values($general->login_background_images ?? []);

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
        $this->cooperative_address = $coop->cooperative_address;
        $this->cooperative_city = $coop->cooperative_city;
        $this->cooperative_phone = $coop->cooperative_phone;
        $this->signatory_name = $coop->signatory_name;
        $this->signatory_position = $coop->signatory_position;

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
            'loginImageUploads' => ['nullable', 'array'],
            'loginImageUploads.*' => ['image', 'max:4096'],
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
            'cooperative_address' => ['nullable', 'string', 'max:255'],
            'cooperative_city' => ['nullable', 'string', 'max:100'],
            'cooperative_phone' => ['nullable', 'string', 'max:50'],
            'signatory_name' => ['nullable', 'string', 'max:100'],
            'signatory_position' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function resetTheme(): void
    {
        $this->theme_primary = null;

        $this->theme_secondary = null;
    }

    /**
     * Hapus satu gambar latar login dari daftar tersimpan (klik Simpan untuk menerapkan).
     */
    public function removeLoginImage(int $index): void
    {
        unset($this->login_background_images[$index]);
        $this->login_background_images = array_values($this->login_background_images);
    }

    /**
     * Geser urutan gambar (-1 = naik, +1 = turun). Urutan ini dipakai slideshow login.
     */
    public function moveLoginImage(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (! isset($this->login_background_images[$index], $this->login_background_images[$target])) {
            return;
        }

        [$this->login_background_images[$index], $this->login_background_images[$target]] =
            [$this->login_background_images[$target], $this->login_background_images[$index]];
        $this->login_background_images = array_values($this->login_background_images);
    }

    /**
     * Kosongkan semua gambar → panel login kembali ke mode teks (gradient brand).
     */
    public function clearLoginImages(): void
    {
        $this->login_background_images = [];
        $this->reset('loginImageUploads');
    }

    public function save(): void
    {
        $this->validate();

        // Snapshot nilai lama SEBELUM dimutasi, untuk audit log (lihat item 2).
        $before = $this->settingsSnapshot();

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

        // Tambahkan upload gambar login baru ke daftar (dibatasi MAX_LOGIN_IMAGES).
        foreach ($this->loginImageUploads as $upload) {
            if (count($this->login_background_images) >= self::MAX_LOGIN_IMAGES) {
                break;
            }
            $this->login_background_images[] = $upload->store('branding/login', 'public');
        }
        $general->login_background_images = array_values($this->login_background_images);

        $general->save();
        $this->logo_path = $general->logo_path;
        $this->favicon_path = $general->favicon_path;
        $this->login_background_images = array_values($general->login_background_images);
        $this->reset('logoUpload', 'faviconUpload', 'loginImageUploads');

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
        $coop->cooperative_address = $this->cooperative_address ?: null;
        $coop->cooperative_city = $this->cooperative_city ?: null;
        $coop->cooperative_phone = $this->cooperative_phone ?: null;
        $coop->signatory_name = $this->signatory_name ?: null;
        $coop->signatory_position = $this->signatory_position ?: null;
        $coop->save();

        // Catat perubahan ke audit log (hanya field yang benar-benar berubah).
        $this->logSettingsChange($before, $this->settingsSnapshot());

        $this->dispatch('toast', type: 'success', message: 'Pengaturan tersimpan. Muat ulang halaman untuk menerapkan tema baru ke seluruh aplikasi.');
    }

    /**
     * Ambil snapshot nilai pengaturan saat ini untuk perbandingan audit.
     * Password SMTP tidak disimpan mentah — hanya penanda terisi/kosong.
     *
     * @return array<string, string>
     */
    private function settingsSnapshot(): array
    {
        $general = app(GeneralSettings::class);
        $mail = app(MailSettings::class);
        $coop = app(CooperativeSettings::class);

        return [
            'Nama Aplikasi' => (string) $general->app_name,
            'Warna Primer' => (string) $general->theme_primary,
            'Warna Sekunder' => (string) $general->theme_secondary,
            'Logo' => (string) $general->logo_path,
            'Favicon' => (string) $general->favicon_path,
            'Jumlah Gambar Login' => (string) count($general->login_background_images ?? []),
            'SMTP Host' => (string) $mail->mail_host,
            'SMTP Port' => (string) $mail->mail_port,
            'SMTP Username' => (string) $mail->mail_username,
            'SMTP Password' => filled($mail->mail_password) ? '••••••' : '(kosong)',
            'SMTP Enkripsi' => (string) $mail->mail_encryption,
            'Email Pengirim' => (string) $mail->mail_from_address,
            'Nama Pengirim' => (string) $mail->mail_from_name,
            'Simpanan Pokok' => (string) $coop->savings_pokok_amount,
            'Wajib Belanja / Bulan' => (string) $coop->savings_wajib_belanja_amount,
            'Minimal Setor Sukarela' => (string) $coop->savings_sukarela_min,
            'Biaya Admin Pinjaman' => (string) $coop->loan_admin_fee_rate,
            'Rasio SWP' => (string) $coop->loan_swp_rate,
            'Rasio Jasa' => (string) $coop->loan_interest_rate,
            'Rasio Tabungan Berjangka' => (string) $coop->loan_time_deposit_rate,
            'Batas Pinjaman Jangka Pendek' => (string) $coop->loan_short_term_max,
            'Alamat Koperasi' => (string) $coop->cooperative_address,
            'Kota Koperasi' => (string) $coop->cooperative_city,
            'Telepon Koperasi' => (string) $coop->cooperative_phone,
            'Nama Penandatangan' => (string) $coop->signatory_name,
            'Jabatan Penandatangan' => (string) $coop->signatory_position,
        ];
    }

    /**
     * Log perubahan pengaturan ke activity log — hanya field yang nilainya
     * berubah, lengkap dengan nilai lama → nilai baru per field.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private function logSettingsChange(array $before, array $after): void
    {
        $changes = [];
        foreach ($after as $label => $newValue) {
            $oldValue = $before[$label] ?? '';
            if ($oldValue !== $newValue) {
                $changes[$label] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        if ($changes === []) {
            return;
        }

        activity()
            ->causedBy(auth()->user())
            ->event('updated')
            ->withProperties(['changes' => $changes])
            ->log('Memperbarui pengaturan: '.implode(', ', array_keys($changes)));
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
