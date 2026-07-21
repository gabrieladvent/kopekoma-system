<?php

namespace App\Filament\Auth;

use App\Settings\GeneralSettings;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Login extends BaseLogin
{
    protected static string $layout = 'filament.auth.split-layout';

    protected function getLayoutData(): array
    {
        return array_merge(parent::getLayoutData(), [
            'appName' => $this->settingValue('app_name') ?: config('app.name'),
            'logoUrl' => $this->assetUrl($this->settingValue('logo_path')),
        ]);
    }

    private function settingValue(string $key): ?string
    {
        try {
            if (! Schema::hasTable('settings')) {
                return null;
            }

            return app(GeneralSettings::class)->{$key} ?? null;
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
