<?php

namespace App\Support;

use App\Settings\CooperativeSettings;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Kop + blok tanda tangan untuk laporan PDF. Menggabungkan identitas aplikasi
 * (`GeneralSettings`: app_name + logo) dengan identitas koperasi
 * (`CooperativeSettings`: alamat/kota/telepon + penandatangan — field baru ADR
 * item 7). Logo diubah ke data URI agar dompdf tak perlu akses file/remote.
 */
class ReportLetterhead
{
    /**
     * @return array<string, string|null>
     */
    public static function make(): array
    {
        $general = app(GeneralSettings::class);
        $coop = app(CooperativeSettings::class);

        return [
            'app_name' => $general->app_name,
            'address' => $coop->cooperative_address,
            'city' => $coop->cooperative_city,
            'phone' => self::displayPhone($coop->cooperative_phone),
            'signatory_name' => $coop->signatory_name,
            'signatory_position' => $coop->signatory_position,
            'logo' => self::logoDataUri($general->logo_path),
        ];
    }

    /**
     * Rapikan nomor telepon untuk kop: normalisasi awalan lokal (0 / 62) jadi
     * format internasional "+62 …". Nilai yang sudah bawa "+" ditampilkan apa
     * adanya (mis. nomor luar negeri).
     */
    private static function displayPhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return preg_replace('/\s+/', ' ', $raw);
        }

        // Buang awalan 62 lalu 0, sisakan national significant number.
        $national = ltrim((string) preg_replace('/^62/', '', $raw), '0');
        $national = trim((string) preg_replace('/\s+/', ' ', $national));

        return $national === '' ? null : '+62 '.$national;
    }

    /**
     * Baca logo dari disk publik dan encode jadi data URI. null bila tak diset
     * atau file hilang — blade menyembunyikan slot logo.
     */
    private static function logoDataUri(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
    }
}
