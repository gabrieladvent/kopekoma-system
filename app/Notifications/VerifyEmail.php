<?php

namespace App\Notifications;

use App\Settings\GeneralSettings;
use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email verifikasi akun berbranding koperasi (ADR — item desain email).
 * Memakai nama aplikasi dari GeneralSettings dan template markdown khusus,
 * menggantikan email verifikasi bawaan Laravel yang generik.
 */
class VerifyEmail extends BaseVerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);
        $appName = app(GeneralSettings::class)->app_name ?: config('app.name');

        return (new MailMessage)
            ->subject('Verifikasi Alamat Email — '.$appName)
            ->markdown('emails.verify-email', [
                'url' => $url,
                'appName' => $appName,
                'name' => $notifiable->name ?? null,
            ]);
    }
}
