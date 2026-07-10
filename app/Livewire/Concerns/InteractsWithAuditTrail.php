<?php

namespace App\Livewire\Concerns;

use Spatie\Activitylog\Models\Activity;

trait InteractsWithAuditTrail
{
    public ?int $auditId = null;

    public bool $showAudit = false;

    public const AUDIT_EVENT_LABELS = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
    ];

    public const AUDIT_EVENT_COLORS = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
        'restored' => 'primary',
    ];

    public function viewAudit(int $id): void
    {
        $this->auditId = $id;

        $this->showAudit = true;
    }

    public function closeAudit(): void
    {
        $this->showAudit = false;
    }

    public function auditEventLabel(?string $event): string
    {
        return self::AUDIT_EVENT_LABELS[$event] ?? ucfirst((string) $event);
    }

    public function auditEventColor(?string $event): string
    {
        return self::AUDIT_EVENT_COLORS[$event] ?? 'neutral';
    }

    /**
     * Bangun baris diff [kolom, sebelum, sesudah] dari properties activity.
     *
     * @return array<int, array{label: string, old: string, new: string}>
     */
    public function auditDiff(?Activity $activity): array
    {
        if (! $activity) {
            return [];
        }

        $new = (array) ($activity->properties['attributes'] ?? []);

        $old = (array) ($activity->properties['old'] ?? []);

        $keys = array_keys($new + $old);

        $rows = [];

        foreach ($keys as $key) {
            $rows[] = [
                'label' => $this->auditFieldLabel($key),
                'old' => array_key_exists($key, $old) ? $this->formatAuditFieldValue($key, $old[$key]) : '—',
                'new' => array_key_exists($key, $new) ? $this->formatAuditFieldValue($key, $new[$key]) : '—',
            ];
        }

        return $rows;
    }

    protected function auditFieldLabel(string $key): string
    {
        return $this->defaultAuditFieldLabel($key);
    }

    protected function defaultAuditFieldLabel(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    protected function formatAuditFieldValue(string $key, mixed $value): string
    {
        return $this->defaultFormatAuditFieldValue($key, $value);
    }

    protected function defaultFormatAuditFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        return (string) $value;
    }
}
