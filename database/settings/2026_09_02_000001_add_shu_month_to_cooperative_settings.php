<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Bulan pembagian SHU — jendela pencairan Tabungan Berjangka.
     *
     * Aturan koperasi: Tabungan Berjangka dikembalikan satu kali dalam setahun,
     * BERSAMAAN pembagian SHU. Sebelum setting ini ada, yang bisa ditegakkan
     * hanyalah "12 bulan sejak pencairan terakhir" — aturan yang melayang per
     * anggota: satu orang cair Januari, yang lain Juli, keduanya sah "sekali
     * setahun" tapi tak satu pun bersamaan SHU.
     *
     * Sengaja BULAN, bukan tanggal pasti: SHU dibagikan setelah RAT, dan tanggal
     * RAT bergeser tiap tahun. Bulan cukup presisi untuk menjawab "kapan boleh",
     * dan mustahil salah set.
     *
     * NULL = belum ditetapkan → sistem jatuh ke aturan 12 bulan berjalan. Tidak
     * ada yang rusak sebelum koperasi memutuskan.
     */
    public function up(): void
    {
        $this->migrator->add('cooperative.shu_distribution_month', null);
    }
};
