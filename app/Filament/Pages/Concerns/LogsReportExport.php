<?php

namespace App\Filament\Pages\Concerns;

/**
 * Audit log untuk export laporan (ADR item 3c). Mencatat cukup untuk melacak
 * kebocoran PII tanpa MENYIMPAN PII: aktor (model User, bukan sekadar id),
 * format (pdf/excel), SETIAP parameter filter dengan sentinel eksplisit
 * (`ALL_OPD`/`ALL_MEMBER` saat dikosongkan — export tanpa filter = kasus paling
 * sensitif, jangan tampak seperti filter sempit), date-range, dan row count.
 * Tidak ada nama/NIK di properties — hanya id + hitungan.
 */
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
            // Sentinel eksplisit: dikosongkan = seluruh koperasi, harus terlihat jelas di audit.
            'agency_id' => ($filters['agency_id'] ?? null) ?: 'ALL_OPD',
            'member_id' => ($filters['member_id'] ?? null) ?: 'ALL_MEMBER',
            'rows' => $rowCount,
        ];

        // Filter khusus laporan setoran — hanya disertakan bila ada di form.
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
