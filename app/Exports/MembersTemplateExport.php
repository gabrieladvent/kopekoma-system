<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class MembersTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'nik',
            'nama',
            'nip',
            'kode_opd',
            'kode_golongan',
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'jabatan',
            'status_kepegawaian',
            'no_rekening_gaji',
            'nama_bank',
            'alamat',
            'no_hp',
            'tanggal_bergabung',
            'nama_ahli_waris',
            'hubungan_ahli_waris',
            'no_hp_ahli_waris',
            'status',
        ];
    }

    /**
     * One example row + one hint row documenting the allowed values.
     *
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return [
            [
                '3201234567890001',
                'Budi Santoso',
                '199001012020121001',
                'OPD0001',
                'GOL-1',
                'Semarang',
                '1990-01-01',
                'L',
                'Staf',
                'ASN',
                '1234567890',
                'BRI',
                'Jl. Merdeka No. 1',
                '081234567890',
                '2026-01-01',
                'Siti',
                'Istri',
                '081298765432',
                'Aktif',
            ],
            [
                'NIK 16 digit unik',
                'wajib',
                'wajib utk ASN',
                'kode OPD terdaftar',
                'kode golongan terdaftar',
                'wajib',
                'YYYY-MM-DD',
                'L atau P',
                'opsional',
                'ASN atau Honorer',
                'wajib',
                'opsional',
                'wajib',
                'tanpa 0 depan',
                'YYYY-MM-DD',
                'wajib',
                'Istri/Suami/Anak/Orang Tua/Saudara Kandung/Lainnya',
                'wajib',
                'Aktif/Non-Aktif/Keluar/Meninggal',
            ],
        ];
    }

    public function title(): string
    {
        return 'Template Anggota';
    }
}
