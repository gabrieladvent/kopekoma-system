<?php

namespace App\Exports;

use App\Models\Installment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export Excel laporan angsuran. Sumber data satu-satunya = service (rows sudah
 * eager-load rantai loan.member.agency + signed_amount). Kolom = whitelist
 * identitas minimum + kolom transaksi; PII berat TIDAK diekspor.
 */
class InstallmentReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    private const TOTAL_MARKER = '__total__';

    /**
     * @param  Collection<int, Installment>  $rows
     */
    public function __construct(
        private Collection $rows,
        private string $total,
    ) {}

    /**
     * @return Collection<int, mixed>
     */
    public function collection(): Collection
    {
        return $this->rows->concat([[self::TOTAL_MARKER => $this->total]]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Tanggal Bayar',
            'No. Pinjaman',
            'Angsuran ke',
            'No. Anggota',
            'Nama',
            'OPD',
            'Reversal',
            'Nominal (net)',
        ];
    }

    /**
     * @param  Installment|array<string, mixed>  $row
     * @return array<int, string|null>
     */
    public function map($row): array
    {
        if (is_array($row) && isset($row[self::TOTAL_MARKER])) {
            return ['', '', '', '', '', '', 'TOTAL (net)', $row[self::TOTAL_MARKER]];
        }

        return [
            optional($row->payment_date)->format('Y-m-d'),
            $row->loan?->loan_number,
            $row->installment_number,
            $row->loan?->member?->member_number,
            $row->loan?->member?->full_name,
            $row->loan?->member?->agency?->agency_name,
            $row->is_reversal ? 'Ya' : 'Tidak',
            $row->signed_amount,
        ];
    }

    public function title(): string
    {
        return 'Laporan Angsuran';
    }
}
