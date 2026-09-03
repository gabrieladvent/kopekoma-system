<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SWP & Tabungan Berjangka jadi simpanan sungguhan.
     *
     * Sebelum ini keduanya **tak punya satu pun baris setoran**. Saldonya
     * dihitung ulang tiap kali dibaca — SWP dari `SUM(loans.swp_amount)`,
     * Tabungan Berjangka dari `monthly_time_deposit × jumlah angsuran terbayar`.
     * Konsekuensinya: tak ada riwayat. Anggota tak bisa melihat kapan uangnya
     * masuk, dari pinjaman mana, dan berapa per transaksi — yang ada hanya satu
     * angka hasil hitungan, tanpa buku mutasi di belakangnya.
     *
     * Sekarang keduanya diperlakukan seperti `pokok`/`wajib`/`sukarela`: punya
     * baris `savings_deposits` bernomor transaksi, bertanggal, bisa dibalik.
     * Yang membedakan hanya **pintu masuknya** — tak ada setoran manual; SWP
     * lahir saat pinjaman cair, Tabungan Berjangka saat angsuran dibayar.
     */
    public function up(): void
    {
        Schema::table('savings_deposits', function (Blueprint $table) {
            // Tanpa `->index()`: indeksnya sudah dibuat migrasi pembuat tabel,
            // dan MySQL menolak nama indeks ganda.
            $table->enum('savings_type', [
                'pokok', 'wajib', 'hari_raya', 'wajib_belanja', 'sukarela',
                'swp', 'tabungan_berjangka',
            ])->change();
        });
    }

    /**
     * Penyempitan enum SENGAJA TIDAK DIBALIK.
     *
     * Begitu ada satu saja pinjaman cair, kolomnya berisi `swp`. `MODIFY` ke
     * daftar lama lalu gagal (mode ketat) atau mengosongkan nilainya (mode
     * longgar) — dan DDL MySQL tidak transaksional, jadi kegagalannya
     * meninggalkan keadaan setengah jadi. Membiarkan enum lebih longgar aman:
     * nilai lama tetap sah, kode lama tak pernah menulis nilai baru.
     *
     * Jalan mundur untuk skema tetap restore dump, bukan `down()`.
     */
    public function down(): void
    {
        // sengaja kosong — lihat catatan di atas.
    }
};
