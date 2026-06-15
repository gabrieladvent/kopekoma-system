<?php

namespace App\Filament\Concerns;

/**
 * Shared formatting for Spatie activity-log "event" values, used by both the
 * standalone ActivityResource and the reusable AuditTrailRelationManager so
 * labels/colors stay consistent everywhere.
 */
trait FormatsActivity
{
    /** @var array<string, string> */
    protected static array $activityEventLabels = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
    ];

    /** @var array<string, string> */
    protected static array $activityEventColors = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
        'restored' => 'info',
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
