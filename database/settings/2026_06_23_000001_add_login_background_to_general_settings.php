<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Pre-seed 3 gambar contoh agar slideshow login langsung bisa dilihat.
        // Kosongkan daftar ini di menu Pengaturan untuk kembali ke panel teks.
        $this->migrator->add('general.login_background_images', [
            'images/login/sample-1.svg',
            'images/login/sample-2.svg',
            'images/login/sample-3.svg',
        ]);
    }

    public function down(): void
    {
        $this->migrator->delete('general.login_background_images');
    }
};
