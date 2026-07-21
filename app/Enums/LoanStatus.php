<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status siklus hidup pinjaman.
 *
 * Backing value = nilai yang tersimpan di kolom `loans.status` (string, lihat
 * migrasi allow_dibatalkan_loan_status). Jangan ubah string-nya tanpa migrasi
 * data — nilai lama di database mengacu ke string ini.
 *
 * Sebelumnya status berupa string literal ('Cair'/'Lunas'/'Dibatalkan') tersebar
 * di service, Livewire, Filament, dan Blade — termasuk peta warna badge yang
 * sudah menyimpang (Filament 'gray' vs Blade 'neutral' untuk Dibatalkan). Enum
 * ini menjadikannya satu sumber kebenaran.
 */
enum LoanStatus: string implements HasColor, HasLabel
{
    case Cair = 'Cair';
    case Lunas = 'Lunas';
    case Dibatalkan = 'Dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Cair => 'Cair',
            self::Lunas => 'Lunas',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Kontrak Filament — dipakai otomatis oleh badge tabel/infolist. */
    public function getLabel(): string
    {
        return $this->label();
    }

    /** Kontrak Filament — warna badge di panel. */
    public function getColor(): string
    {
        return $this->filamentColor();
    }

    /** Warna untuk komponen x-ui.badge (neutral|success|warning|danger|primary). */
    public function color(): string
    {
        return match ($this) {
            self::Cair => 'primary',
            self::Lunas => 'success',
            self::Dibatalkan => 'neutral',
        };
    }

    /** Warna untuk badge Filament (gray|success|warning|danger|primary|info). */
    public function filamentColor(): string
    {
        return match ($this) {
            self::Cair => 'primary',
            self::Lunas => 'success',
            self::Dibatalkan => 'gray',
        };
    }

    /**
     * value => label untuk <select> dan filter tabel.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
