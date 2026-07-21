<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah status `Dibatalkan` untuk pinjaman salah-input yang dikoreksi:
 * record DIPERTAHANKAN sebagai histori (bukan dihapus), dikeluarkan dari daftar
 * aktif. Kolom enum dilebarkan jadi string agar portabel lintas MySQL/SQLite;
 * nilai sah dijaga di level aplikasi (form options + scope + status map).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('status')->default('Cair')->change();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->enum('status', ['Cair', 'Lunas'])->default('Cair')->change();
        });
    }
};
