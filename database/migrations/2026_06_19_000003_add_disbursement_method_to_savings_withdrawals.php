<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_withdrawals', function (Blueprint $table) {
            // Metode pengembalian SWP/Tabungan Berjangka saat pelunasan pinjaman
            // (ADR D8): tunai atau transfer. Nullable — hanya relevan untuk baris
            // refund pinjaman; pencairan simpanan biasa membiarkannya null.
            $table->enum('disbursement_method', ['tunai', 'transfer'])->nullable()->after('related_loan_id');
        });
    }

    public function down(): void
    {
        Schema::table('savings_withdrawals', function (Blueprint $table) {
            $table->dropColumn('disbursement_method');
        });
    }
};
