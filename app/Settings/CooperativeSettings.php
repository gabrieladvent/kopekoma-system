<?php

namespace App\Settings;

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

    public static function group(): string
    {
        return 'cooperative';
    }
}
