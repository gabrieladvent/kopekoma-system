<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status baris jadwal angsuran. Backing value = kolom
 * `installment_schedules.status`.
 *
 * Catatan: label yang DITAMPILKAN di tabel jadwal ("Nunggak" / "Belum Jatuh
 * Tempo") diturunkan dari due_date di SchedulesRelationManager::statusLabel,
 * bukan dari enum ini — enum hanya mewakili dua status penyimpanan.
 */
enum InstallmentScheduleStatus: string implements HasColor, HasLabel
{
    case BelumBayar = 'Belum Bayar';
    case Terbayar = 'Terbayar';

    public function label(): string
    {
        return $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::BelumBayar => 'warning',
            self::Terbayar => 'success',
        };
    }

    public function filamentColor(): string
    {
        return match ($this) {
            self::BelumBayar => 'gray',
            self::Terbayar => 'success',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->filamentColor();
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
