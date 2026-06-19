<?php

namespace App\Providers;

use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Actions\DeleteAction as PageDeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyGeneralSettings();
        $this->configureDeleteNotifications();
        $this->configureRateLimiters();
    }

    /**
     * Rate limiter API toko (ADR D1/D3): endpoint token anti brute-force secret
     * (key per client_id + IP), endpoint purchase anti enumerasi/abuse (per token+IP).
     */
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

    /**
     * Ensure every delete action (page header, table row, bulk) shows a
     * notification with a description, not just a title.
     */
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

    /**
     * Push the stored general settings into runtime config so the app name and
     * mail "from" address reflect what the admin set. Guarded so it is a no-op
     * before the settings table exists (fresh install / migration).
     */
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
