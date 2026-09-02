<?php

namespace App\Settings;

use App\Services\WithdrawalWorkflow;
use Spatie\LaravelSettings\Settings;

class CooperativeSettings extends Settings
{
    // Simpanan
    public float $savings_pokok_amount;

    public float $savings_wajib_belanja_amount;

    public float $savings_sukarela_min;

    // Pinjaman
    public float $loan_admin_fee_rate;

    public float $loan_swp_rate;

    public float $loan_interest_rate;

    public float $loan_time_deposit_rate;

    public float $loan_short_term_max;

    /**
     * Bulan pembagian SHU (1–12), atau NULL bila belum ditetapkan.
     *
     * Menjadi JENDELA pencairan Tabungan Berjangka — aturan koperasi
     * mengembalikannya sekali setahun bersamaan pembagian SHU. NULL membuat
     * sistem jatuh ke aturan 12 bulan berjalan sejak pencairan terakhir; itu
     * aturan yang lebih longgar dan melayang per anggota, tapi aman dipakai
     * sampai koperasi menetapkan bulannya.
     *
     * Lihat {@see WithdrawalWorkflow::assertTimeDepositSchedule()}.
     */
    public ?int $shu_distribution_month = null;

    // Identitas koperasi untuk kop + blok tanda tangan laporan PDF (ADR item 7).
    public ?string $cooperative_address;

    public ?string $cooperative_city;

    public ?string $cooperative_phone;

    public ?string $signatory_name;

    public ?string $signatory_position;

    public static function group(): string
    {
        return 'cooperative';
    }
}
