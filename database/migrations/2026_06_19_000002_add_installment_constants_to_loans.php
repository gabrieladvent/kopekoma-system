<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Konstanta tagihan angsuran per bulan, dikunci saat akad (ADR D1b).
            // Sumber kebenaran "tagihan"; uang nyata = installments.*_paid (D5).
            $table->decimal('monthly_principal', 18, 2)->nullable()->after('term_months');
            $table->decimal('monthly_interest', 18, 2)->nullable()->after('monthly_principal');
            $table->decimal('monthly_time_deposit', 18, 2)->nullable()->after('monthly_interest');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['monthly_principal', 'monthly_interest', 'monthly_time_deposit']);
        });
    }
};
