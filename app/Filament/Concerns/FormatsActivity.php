<?php

namespace App\Filament\Concerns;

trait FormatsActivity
{
    /** @var array<string, string> */
    protected static array $activityEventLabels = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
        'approved' => 'Disetujui (ACC)',
        'disbursed' => 'Dicairkan',
        'rejected' => 'Ditolak',
        'reversal' => 'Reversal',
        'batch_potong_gaji' => 'Batch Potong Gaji',
    ];

    /** @var array<string, string> */
    protected static array $activityEventColors = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
        'restored' => 'info',
        'approved' => 'info',
        'disbursed' => 'success',
        'rejected' => 'danger',
        'reversal' => 'danger',
        'batch_potong_gaji' => 'primary',
    ];

    public static function activityEventLabel(?string $state): string
    {
        return static::$activityEventLabels[$state] ?? (string) $state;
    }

    public static function activityEventColor(?string $state): string
    {
        return static::$activityEventColors[$state] ?? 'gray';
    }

    /** @return array<string, string> */
    public static function activityEventLabels(): array
    {
        return static::$activityEventLabels;
    }
}
