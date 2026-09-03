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

    /**
     * Urutan tampil bagian dari satu baris array (mis. `skipped_rows`).
     *
     * MySQL menyimpan `properties` sebagai kolom JSON dan MENORMALKAN urutan
     * kunci objek (panjang kunci dulu, baru leksikografis), jadi urutan kunci
     * pada payload tak bisa dipercaya: `schedule_id · reason` berbalik jadi
     * `reason · schedule_id` begitu log dibaca kembali dari MySQL. Urutan
     * dipatok di sini supaya panel audit terbaca sama di sqlite (test) maupun
     * MySQL (produksi).
     */
    public const AUDIT_PART_ORDER = ['schedule_id', 'loan_number', 'member', 'reason'];

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

        if (is_array($value)) {
            return $this->flattenAuditArray($value);
        }

        return (string) $value;
    }

    private function flattenAuditArray(array $value): string
    {
        if ($value === []) {
            return '—';
        }

        $lines = [];

        foreach ($value as $key => $item) {
            $text = is_array($item)
                ? $this->joinAuditParts($item)
                : $this->defaultFormatAuditFieldValue((string) $key, $item);

            if ($text === '' || $text === '—') {
                continue;
            }

            $lines[] = array_is_list($value) ? $text : $this->defaultAuditFieldLabel((string) $key).': '.$text;
        }

        return $lines === [] ? '—' : implode("\n", $lines);
    }

    private function joinAuditParts(array $item): string
    {
        $parts = [];

        foreach ($this->orderAuditParts($item) as $key => $sub) {
            $text = is_array($sub)
                ? $this->joinAuditParts($sub)
                : $this->defaultFormatAuditFieldValue((string) $key, $sub);

            if ($text === '' || $text === '—') {
                continue;
            }

            $parts[] = $text;
        }

        return implode(' · ', $parts);
    }

    /**
     * Susun ulang bagian baris mengikuti AUDIT_PART_ORDER; kunci di luar daftar
     * menyusul di belakang dengan urutan aslinya. List (array berindeks) sudah
     * punya urutan sendiri dan dibiarkan apa adanya.
     */
    private function orderAuditParts(array $item): array
    {
        if (array_is_list($item)) {
            return $item;
        }

        $priority = array_flip(self::AUDIT_PART_ORDER);

        $known = [];

        $rest = [];

        foreach ($item as $key => $sub) {
            if (array_key_exists($key, $priority)) {
                $known[$priority[$key]] = [$key, $sub];

                continue;
            }

            $rest[] = [$key, $sub];
        }

        ksort($known);

        $ordered = [];

        foreach ([...array_values($known), ...$rest] as [$key, $sub]) {
            $ordered[$key] = $sub;
        }

        return $ordered;
    }
}
