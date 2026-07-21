<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status pencairan simpanan — state machine draft → acc → cair/ditolak.
 *
 * Backing value = kolom `savings_withdrawals.status`. `cair` & `ditolak` terminal;
 * saldo baru berkurang saat `cair`.
 *
 * Transisi yang diizinkan hidup di enum ini (transitionsTo) supaya WithdrawalWorkflow,
 * Filament, dan Livewire memakai satu definisi yang sama — sebelumnya guard transisi
 * tersebar dan sempat menyimpang antar-UI.
 */
enum WithdrawalStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Acc = 'acc';
    case Cair = 'cair';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Acc => 'Disetujui',
            self::Cair => 'Cair',
            self::Ditolak => 'Ditolak',
        };
    }

    /** Warna untuk komponen x-ui.badge (neutral|success|warning|danger|primary). */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Acc => 'primary',
            self::Cair => 'success',
            self::Ditolak => 'danger',
        };
    }

    /** Warna untuk badge Filament (gray|success|warning|danger|primary|info). */
    public function filamentColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Acc => 'info',
            self::Cair => 'success',
            self::Ditolak => 'danger',
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

    /**
     * Status tujuan yang boleh dicapai dari status ini. Terminal → kosong.
     *
     * @return list<self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Acc, self::Ditolak],
            self::Acc => [self::Cair, self::Ditolak],
            self::Cair, self::Ditolak => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitionsTo(), true);
    }

    /**
     * value => label untuk <select>/filter tabel.
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
