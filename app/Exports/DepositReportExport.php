<?php

namespace App\Exports;

use App\Filament\Resources\SavingsDepositResource;
use App\Models\SavingsDeposit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export Excel laporan setoran. Sumber data satu-satunya = service (rows sudah
 * eager-load + signed_amount), TIDAK ada query di sini. Kolom = whitelist
 * identitas minimum (member_number/full_name/agency_name) + kolom transaksi;
 * PII berat (nik/nip/rekening/alamat/heir) sengaja TIDAK diekspor.
 */
class DepositReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /** Penanda baris grand total yang disisipkan di akhir collection. */
    private const TOTAL_MARKER = '__total__';

    /**
     * @param  Collection<int, SavingsDeposit>  $rows
     */
    public function __construct(
        private Collection $rows,
        private string $total,
    ) {}

    /**
     * Baris data + satu baris penanda grand total di akhir (FromCollection tak
     * bisa menyisipkan footer, jadi total ikut sebagai baris map-able).
     *
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
            'Tanggal Setor',
            'Periode',
            'No. Transaksi',
            'No. Anggota',
            'Nama',
            'OPD',
            'Jenis',
            'Metode',
            'Reversal',
            'Nominal (net)',
        ];
    }

    /**
     * @param  SavingsDeposit|array<string, mixed>  $row
     * @return array<int, string|null>
     */
    public function map($row): array
    {
        if (is_array($row) && isset($row[self::TOTAL_MARKER])) {
            return ['', '', '', '', '', '', '', '', 'TOTAL (net)', $row[self::TOTAL_MARKER]];
        }

        return [
            optional($row->deposit_date)->format('Y-m-d'),
            optional($row->period_month)->format('Y-m'),
            $row->transaction_number,
            $row->member?->member_number,
            $row->member?->full_name,
            $row->member?->agency?->agency_name,
            SavingsDepositResource::SAVINGS_TYPES[$row->savings_type] ?? $row->savings_type,
            SavingsDepositResource::DEPOSIT_METHODS[$row->deposit_method] ?? $row->deposit_method,
            $row->is_reversal ? 'Ya' : 'Tidak',
            $row->signed_amount,
        ];
    }

    public function title(): string
    {
        return 'Laporan Setoran';
    }
}
