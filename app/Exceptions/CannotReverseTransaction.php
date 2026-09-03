<?php

namespace App\Exceptions;

use RuntimeException;

class CannotReverseTransaction extends RuntimeException
{
    /**
     * Payload jejak audit yang harus tetap tercatat WALAU transaksinya di-rollback.
     *
     * Penolakan pembatalan terdeteksi di dalam `DB::transaction()`, dan lemparan
     * ini me-rollback semuanya — termasuk baris `activity_log` yang ditulis di
     * sana. Jadi jejak "pembatalan ditolak" yang dicatat di titik deteksi tak
     * pernah benar-benar ada; padahal justru itu peristiwa yang paling perlu
     * terlihat, karena bentuknya sama persis dengan percobaan menarik kembali
     * uang yang sudah terpakai. Angkanya dititipkan di sini lalu ditulis
     * pemanggil setelah rollback.
     *
     * @var array<string, mixed>|null
     */
    public ?array $auditPayload = null;

    /** @param  array<string, mixed>  $payload */
    public function withAuditPayload(array $payload): self
    {
        $this->auditPayload = $payload;

        return $this;
    }

    public static function alreadyReversed(): self
    {
        return new self('Transaksi sudah pernah di-reversal.');
    }

    public static function isAReversal(): self
    {
        return new self('Tidak dapat me-reversal sebuah baris reversal.');
    }

    public static function memberInactive(): self
    {
        return new self('Tidak dapat me-reversal transaksi anggota yang sudah Keluar/Meninggal.');
    }

    public static function reasonRequired(): self
    {
        return new self('Alasan reversal wajib diisi (minimal 5 karakter).');
    }

    /**
     * Pembatalan ini akan membuat saldo Titipan Pokok minus (ADR 2026-08-28):
     * titipannya sudah terpakai memotong angsuran lain, jadi angsuran itu harus
     * dibatalkan lebih dulu.
     */
    public static function overpaymentCreditSpent(?string $blockingNumber): self
    {
        if ($blockingNumber === null) {
            return new self('Pembatalan ini membuat saldo Titipan Pokok menjadi minus — titipannya sudah terpakai. Batalkan dulu angsuran yang memakainya.');
        }

        return new self("Pembatalan ini membuat saldo Titipan Pokok menjadi minus — titipannya sudah dipakai angsuran {$blockingNumber}. Batalkan angsuran {$blockingNumber} lebih dulu.");
    }

    public static function pairedInstallmentDebit(): self
    {
        return new self('Debit angsuran dari saldo simpanan hanya bisa dibalik lewat pembatalan angsurannya, bukan dari menu Pencairan.');
    }

    /**
     * Setoran SWP / Tabungan Berjangka adalah pasangan dari pencairan pinjaman
     * dan pembayaran angsuran. Dibalik sendirian, saldo anggota turun sementara
     * pinjaman & angsurannya tetap berdiri.
     */
    public static function loanOwnedDeposit(string $type): self
    {
        $where = $type === 'swp'
            ? 'membatalkan pinjamannya'
            : 'membatalkan angsuran yang bersangkutan';

        return new self("Setoran {$type} tidak bisa dibatalkan sendiri — ia mengikuti pinjaman. Lakukan lewat {$where}.");
    }
}
