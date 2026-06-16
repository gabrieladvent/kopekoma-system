<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Settings\GeneralSettings;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $settings = $this->generalSettings();
        $appName = $settings?->app_name ?: config('app.name');

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName($appName)
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors([
                'primary' => Color::Emerald,
            ]);

        if ($logo = $this->assetUrl($settings?->logo_path)) {
            // Render the logo AND the app name together. Filament hides the
            // brand name once a brand logo is set, so we build the brand as
            // HTML that keeps the name visible alongside the image. Inline
            // styles are used for sizing because arbitrary Tailwind classes in
            // a PHP string are not present in the compiled stylesheet.
            $panel->brandLogo(new HtmlString(
                '<span style="display:flex;align-items:center;gap:.5rem;">'
                .'<img src="'.e($logo).'" alt="'.e($appName).'" style="height:2rem;width:auto;flex:none;" />'
                .'<span style="font-size:1rem;font-weight:700;line-height:1.25;white-space:nowrap;" class="text-gray-950 dark:text-white">'.e($appName).'</span>'
                .'</span>'
            ));
        }

        if ($favicon = $this->assetUrl($settings?->favicon_path)) {
            $panel->favicon($favicon);
        }

        return $panel
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ]);
    }

    /**
     * Read the general settings safely — returns null before the settings
     * table/values exist (e.g. during a fresh install or migration).
     */
    private function generalSettings(): ?GeneralSettings
    {
        try {
            if (! Schema::hasTable('settings')) {
                return null;
            }

            return app(GeneralSettings::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function assetUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
