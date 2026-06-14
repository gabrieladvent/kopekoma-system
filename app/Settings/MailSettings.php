<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MailSettings extends Settings
{
    public string $mail_host;

    public int $mail_port;

    public ?string $mail_username;

    public ?string $mail_password;

    /** tls | ssl | null (tanpa enkripsi) */
    public ?string $mail_encryption;

    public string $mail_from_address;

    public string $mail_from_name;

    public static function group(): string
    {
        return 'mail';
    }

    /**
     * Simpan password SMTP dalam keadaan terenkripsi di database.
     */
    public static function encrypted(): array
    {
        return ['mail_password'];
    }

    /**
     * Skema transport Symfony: smtps untuk SSL (port 465), smtp untuk
     * STARTTLS/tanpa enkripsi.
     */
    public function scheme(): string
    {
        return $this->mail_encryption === 'ssl' ? 'smtps' : 'smtp';
    }
}
