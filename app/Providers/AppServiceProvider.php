<?php

namespace App\Providers;

use App\Logging\RedactSensitiveLogContext;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Actions\DeleteAction as PageDeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        $this->configureLogRedaction();
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
