<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Default diambil dari konfigurasi mail saat ini (env).
        $this->migrator->add('mail.mail_host', config('mail.mailers.smtp.host', '127.0.0.1'));
        $this->migrator->add('mail.mail_port', (int) config('mail.mailers.smtp.port', 587));
        $this->migrator->add('mail.mail_username', config('mail.mailers.smtp.username'));
        $this->migrator->addEncrypted('mail.mail_password', config('mail.mailers.smtp.password'));
        $this->migrator->add('mail.mail_encryption', 'tls');
        $this->migrator->add('mail.mail_from_address', config('mail.from.address', 'admin@kopekoma.test'));
        $this->migrator->add('mail.mail_from_name', config('mail.from.name', 'KOPEKOMA'));

        // Email aplikasi sekarang dikelola lewat pengaturan mail (from address).
        $this->migrator->deleteIfExists('general.app_email');
    }
};
