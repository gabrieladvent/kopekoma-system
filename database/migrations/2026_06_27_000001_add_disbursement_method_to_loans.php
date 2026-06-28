<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Jenis pencairan pinjaman: tunai atau transfer. Nullable agar
            // pinjaman lama (sebelum kolom ada) tetap valid; UI menampilkan
            // fallback "—" untuk null.
            $table->enum('disbursement_method', ['tunai', 'transfer'])->nullable()->after('disbursement_date');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('disbursement_method');
        });
    }
};
