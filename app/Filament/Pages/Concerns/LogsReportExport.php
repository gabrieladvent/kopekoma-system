<?php

namespace App\Filament\Pages\Concerns;

trait LogsReportExport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    protected function logReportExport(string $report, string $format, array $filters, int $rowCount): void
    {
        $properties = [
            'report' => $report,
            'format' => $format,
            'start' => $filters['start'] ?? null,
            'end' => $filters['end'] ?? null,
            'agency_id' => ($filters['agency_id'] ?? null) ?: 'ALL_OPD',
            'member_id' => ($filters['member_id'] ?? null) ?: 'ALL_MEMBER',
            'rows' => $rowCount,
        ];

        if (array_key_exists('basis', $filters)) {
            $properties['basis'] = $filters['basis'];
        }

        if (array_key_exists('savings_type', $filters)) {
            $properties['savings_type'] = empty($filters['savings_type'])
                ? 'ALL'
                : array_values((array) $filters['savings_type']);
        }

        if (array_key_exists('deposit_method', $filters)) {
            $properties['deposit_method'] = $filters['deposit_method'] ?: 'ALL';
        }

        activity()
            ->causedBy(auth()->user())
            ->event('export')
            ->withProperties($properties)
            ->log("Export laporan {$report} ({$format}): {$rowCount} baris");
    }

    /**
     * Hitung total baris detail dari struktur grouped service (untuk PDF, tanpa
     * query ulang).
     *
     * @param  array{groups: array<int, array<string, mixed>>, grand_total: string}  $grouped
     */
    protected function countGroupedRows(array $grouped): int
    {
        $count = 0;

        foreach ($grouped['groups'] as $group) {
            foreach ($group['members'] as $member) {
                $count += $member['rows']->count();
            }
        }

        return $count;
    }
}
