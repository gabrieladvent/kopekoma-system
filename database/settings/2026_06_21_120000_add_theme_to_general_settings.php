<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.theme_primary', null);
        $this->migrator->add('general.theme_secondary', null);
    }

    public function down(): void
    {
        $this->migrator->delete('general.theme_primary');
        $this->migrator->delete('general.theme_secondary');
    }
};
