<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rentang pengumpulan Hari Raya: setoran hanya boleh di antara start_date &
     * end_date (tanggal terakhir sebelum pembagian). `period_year` tetap dipakai
     * sebagai kunci pengelompokan saldo (D1) — diturunkan dari end_date.
     */
    public function up(): void
    {
        Schema::table('member_holiday_savings', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('period_year');
            $table->date('end_date')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('member_holiday_savings', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};
