<?php

namespace App\Filament\Concerns;

trait FormatsActivity
{
    /** @var array<string, string> */
    protected static array $activityEventLabels = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
        'approved' => 'Disetujui (ACC)',
        'disbursed' => 'Dicairkan',
        'rejected' => 'Ditolak',
        'reversal' => 'Reversal',
        'batch_potong_gaji' => 'Batch Potong Gaji',
        // Event kustom yang selama ini tak terdaftar. Peta ini BUKAN cuma soal
        // label — ia juga menyetir opsi filter di layar Log Aktivitas, jadi
        // event yang tak ada di sini tak bisa dicari sama sekali. Untuk
        // `pencairan_di_luar_jadwal` itu fatal: seluruh nilai kontrolnya ada
        // pada jejaknya, dan pemeriksa tak punya cara memanggilnya.
        'pencairan_di_luar_jadwal' => 'Pencairan di Luar Jadwal',
        'batch_angsuran_potong_gaji' => 'Batch Angsuran Potong Gaji',
        'angsuran' => 'Pembayaran Angsuran',
        'pembatalan_angsuran' => 'Pembatalan Angsuran',
        'pembatalan_ditolak' => 'Pembatalan Ditolak',
        'pelunasan_dipercepat' => 'Pelunasan Dipercepat',
        'kelebihan_bayar' => 'Pengalihan Kelebihan Dana',
        'debit_simpanan_angsuran' => 'Debit Simpanan untuk Angsuran',
        'koreksi' => 'Koreksi / Pembatalan',
    ];

    /** @var array<string, string> */
    protected static array $activityEventColors = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
        'restored' => 'info',
        'approved' => 'info',
        'disbursed' => 'success',
        'rejected' => 'danger',
        'reversal' => 'danger',
        'batch_potong_gaji' => 'primary',
        'pencairan_di_luar_jadwal' => 'danger',
        'batch_angsuran_potong_gaji' => 'primary',
        'angsuran' => 'success',
        'pembatalan_angsuran' => 'danger',
        'pembatalan_ditolak' => 'danger',
        'pelunasan_dipercepat' => 'success',
        'kelebihan_bayar' => 'warning',
        'debit_simpanan_angsuran' => 'warning',
        'koreksi' => 'danger',
    ];

    public static function activityEventLabel(?string $state): string
    {
        return static::$activityEventLabels[$state] ?? (string) $state;
    }

    public static function activityEventColor(?string $state): string
    {
        return static::$activityEventColors[$state] ?? 'gray';
    }

    /** @return array<string, string> */
    public static function activityEventLabels(): array
    {
        return static::$activityEventLabels;
    }
}
