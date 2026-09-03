<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Titipan Pokok — jejak audit per baris + penanda sesi (ADR 2026-08-28).
     *
     * Saldo titipan sendiri TIDAK disimpan: ia diturunkan dari riwayat angsuran
     * (`Loan::overpaymentCredit()`), supaya otomatis benar setelah pembatalan.
     * Yang disimpan hanyalah fakta per-transaksi — ditulis sekali saat baris
     * dibuat dan tidak pernah di-UPDATE.
     */
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            // Titipan pokok yang DIPAKAI baris ini = max(0, tagihan kontrak −
            // amount_paid). NULL pada baris lama (sebelum fitur ini) dan
            // diperlakukan 0 oleh breakdown() maupun rumus saldo.
            $table->decimal('credit_applied', 18, 2)->nullable()->after('amount_paid');

            // Penanda sesi: satu setoran yang menutup beberapa angsuran menandai
            // semua barisnya dengan kunci sesi yang sama. Informatif — dipakai
            // layar pembatalan untuk menampilkan keterkaitan ("satu setoran
            // bersama ANG-…"), bukan untuk memaksa pengelompokan.
            $table->uuid('session_key')->nullable()->after('idempotency_key')->index();

            // Kunci idempotensi per baris diturunkan dari kunci sesi:
            // `kunci_sesi + "-" + urutan`. Kolom uuid (char 36) tidak muat
            // menampung sufiks itu, sehingga dilebarkan. Indeks UNIQUE-nya tetap
            // — justru itu yang menolak baris ganda saat simpan diklik dua kali.
            $table->string('idempotency_key', 64)->change();
        });
    }

    /**
     * Pelebaran `idempotency_key` SENGAJA TIDAK DIBALIK.
     *
     * Versi pertama `down()` menyempitkannya kembali ke `char(36)`, dan itu
     * **rusak**: begitu ada satu saja setoran multi-angsuran, kolomnya berisi
     * kunci turunan 38 karakter. `MODIFY … char(36)` lalu gagal — mode ketat
     * menolak `Data too long`, mode longgar memotongnya sehingga `<uuid>-1` dan
     * `<uuid>-2` bertabrakan di indeks UNIQUE. DDL MySQL tidak transaksional, dan
     * `Migrator::runDown()` baru menghapus catatan migrasi SETELAH `down()`
     * selesai — jadi kegagalan itu meninggalkan keadaan setengah jadi: kolomnya
     * sudah hilang, tapi migrasi masih dianggap ter-apply dan `migrate` tak akan
     * mengembalikannya.
     *
     * Membiarkannya `varchar(64)` aman: UUID 36 karakter muat di dalamnya, dan
     * kode lama tak peduli lebar kolom. Yang dibalik hanya yang memang bisa
     * dibalik tanpa merusak data.
     */
    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn(['credit_applied', 'session_key']);
        });
    }
};
