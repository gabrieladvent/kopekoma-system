<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $app_name;

    public ?string $logo_path;

    public ?string $favicon_path;

    public static function group(): string
    {
        return 'general';
    }
}
