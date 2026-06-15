<?php

namespace App\Providers;

use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Actions\DeleteAction as PageDeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
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
