<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.app_name', 'KOPEKOMA');
        $this->migrator->add('general.app_email', 'admin@kopekoma.test');
        $this->migrator->add('general.logo_path', null);
        $this->migrator->add('general.favicon_path', null);
    }
};
