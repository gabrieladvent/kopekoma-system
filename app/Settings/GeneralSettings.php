<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $app_name;

    public ?string $logo_path;

    public ?string $favicon_path;

    /** Warna brand custom (hex). null = pakai default token CSS (emerald/teal). */
    public ?string $theme_primary;

    public ?string $theme_secondary;

    /**
     * Daftar gambar latar panel kiri halaman login.
     * Kosong = panel teks (gradient brand). 1 gambar = efek Ken Burns.
     * 2+ gambar = slideshow crossfade.
     *
     * @var array<int, string>
     */
    public array $login_background_images = [];

    public static function group(): string
    {
        return 'general';
    }
}
