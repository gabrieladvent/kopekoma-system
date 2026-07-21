<?php

namespace App\Providers;

use App\Logging\RedactSensitiveLogContext;
use App\Providers\Filament\AdminPanelProvider;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Actions\DeleteAction as PageDeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerFilamentPanelForTesting();
    }

    /**
     * Panel admin Filament tidak didaftarkan di bootstrap/providers.php — UI
     * produksi dilayani Livewire. Tapi ~172 tes masih me-render halaman panel,
     * dan tanpa panel terdaftar Filament::auth() melempar "call to a member
     * function auth() on null".
     *
     * Panel hanya dihidupkan saat testing supaya tes itu tetap menjaga kelas
     * Filament yang method statisnya MASIH dipakai produksi (mis.
     * LoanResource::printReceipt, SavingsWithdrawalResource::isLoanRefund).
     *
     * Catatan: ini TIDAK menguji UI Livewire yang sebenarnya dipakai user.
     * Lihat docs/adr/2026-07-20-audit-remediasi.md.
     */
    private function registerFilamentPanelForTesting(): void
    {
        if ($this->app->environment('testing')) {
            $this->app->register(AdminPanelProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyGeneralSettings();
        $this->configureDeleteNotifications();
        $this->configureRateLimiters();
        $this->configureLogRedaction();
        $this->configureGates();
    }

    /**
     * Gate untuk modul Sistem (pengguna, peran, log aktivitas).
     *
     * Sengaja Gate, bukan middleware `role:` milik Spatie: hanya
     * Illuminate\Auth\Middleware\Authorize yang ada di daftar persistent
     * middleware Livewire, sehingga hanya `can:` yang ikut berlaku ulang pada
     * tiap POST /livewire/update. Middleware role Spatie hanya akan menjaga
     * render awal dan membiarkan action berikutnya lolos.
     *
     * Sebelumnya route /sistem/* sama sekali tanpa middleware dan hanya
     * mengandalkan abort_unless di mount() — padahal mount() TIDAK jalan ulang
     * saat update, jadi setiap public method baru lahir tanpa proteksi.
     */
    private function configureGates(): void
    {
        Gate::define('manage-system', fn ($user): bool => $user->hasRole('super_admin'));
    }

    private function configureLogRedaction(): void
    {
        Log::channel(config('logging.default'))
            ->getLogger()
            ->pushProcessor(new RedactSensitiveLogContext);
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('store-token', function (Request $request): Limit {
            $clientId = (string) $request->input('client_id', '');

            return Limit::perMinute(config('store.rate_limit.token_per_minute'))
                ->by($clientId.'|'.$request->ip());
        });

        RateLimiter::for('store-purchase', function (Request $request): Limit {
            return Limit::perMinute(config('store.rate_limit.purchase_per_minute'))
                ->by(($request->user()?->getKey() ?? $request->ip()).'|'.$request->ip());
        });
    }

    private function configureDeleteNotifications(): void
    {
        $single = Notification::make()
            ->success()
            ->title('Data dihapus')
            ->body('Data telah berhasil dihapus dari sistem.');

        PageDeleteAction::configureUsing(fn (PageDeleteAction $action) => $action->successNotification($single));

        TableDeleteAction::configureUsing(fn (TableDeleteAction $action) => $action->successNotification($single));

        DeleteBulkAction::configureUsing(fn (DeleteBulkAction $action) => $action->successNotification(
            Notification::make()
                ->success()
                ->title('Data terpilih dihapus')
                ->body('Seluruh data yang dipilih telah berhasil dihapus.')
        ));
    }

    private function applyGeneralSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            config(['app.name' => app(GeneralSettings::class)->app_name]);

            $mail = app(MailSettings::class);

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.scheme' => $mail->scheme(),
                'mail.mailers.smtp.host' => $mail->mail_host,
                'mail.mailers.smtp.port' => $mail->mail_port,
                'mail.mailers.smtp.username' => $mail->mail_username,
                'mail.mailers.smtp.password' => $mail->mail_password,
                'mail.from.address' => $mail->mail_from_address,
                'mail.from.name' => $mail->mail_from_name,
            ]);
        } catch (\Throwable) {
            // Settings not available yet — keep framework defaults.
        }
    }
}
