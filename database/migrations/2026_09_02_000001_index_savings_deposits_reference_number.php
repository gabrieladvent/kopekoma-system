<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `reference_number` jadi kunci pencarian di jalur panas.
     *
     * Kolomnya sudah ada sejak awal tanpa indeks — dulu itu tak apa, ia hanya
     * catatan bebas. Sejak SWP & Tabungan Berjangka jadi simpanan sungguhan,
     * kolom ini yang menautkan setoran ke pinjaman (`loan_number`) dan ke
     * angsuran (`installment_number`), dan dibaca setiap kali pinjaman cair,
     * pinjaman dibatalkan, dan angsuran dibalik.
     *
     * Tabel ini kini tumbuh ~1 baris per angsuran per anggota per bulan di atas
     * setoran biasa. Tanpa indeks, ketiga jalur itu jadi pemindaian tabel penuh
     * yang memburuk linear — tak terasa di tahun pertama, pasti terasa di tahun
     * ketiga.
     */
    public function up(): void
    {
        Schema::table('savings_deposits', function (Blueprint $table) {
            $table->index(['savings_type', 'reference_number'], 'savings_deposits_type_reference_index');
        });
    }

    public function down(): void
    {
        Schema::table('savings_deposits', function (Blueprint $table) {
            $table->dropIndex('savings_deposits_type_reference_index');
        });
    }
};
