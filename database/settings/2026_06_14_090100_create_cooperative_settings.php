<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('cooperative.savings_pokok_amount', 50000);
        $this->migrator->add('cooperative.savings_wajib_belanja_amount', 100000);
        $this->migrator->add('cooperative.savings_sukarela_min', 0);

        $this->migrator->add('cooperative.loan_admin_fee_rate', 0.01);
        $this->migrator->add('cooperative.loan_swp_rate', 0.01);
        $this->migrator->add('cooperative.loan_interest_rate', 0.0065);
        $this->migrator->add('cooperative.loan_time_deposit_rate', 0.001);
        $this->migrator->add('cooperative.loan_short_term_max', 1000000);
    }
};
