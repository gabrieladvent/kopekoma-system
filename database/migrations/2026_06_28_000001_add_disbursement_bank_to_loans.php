<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Rekening tujuan transfer pinjaman — hanya relevan saat
            // disbursement_method = 'transfer'. Nullable; di-prefill dari rekening
            // payroll anggota tapi disimpan per-pinjaman sebagai jejak historis.
            $table->string('disbursement_bank')->nullable()->after('disbursement_method');
            $table->string('disbursement_account_number')->nullable()->after('disbursement_bank');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['disbursement_bank', 'disbursement_account_number']);
        });
    }
};
