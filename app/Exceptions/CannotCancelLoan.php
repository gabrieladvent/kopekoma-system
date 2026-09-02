<?php

namespace App\Exceptions;

use RuntimeException;

class CannotCancelLoan extends RuntimeException
{
    /**
     * SWP pinjaman ini sudah ditarik anggota, jadi pembatalannya akan membuat
     * saldo simpanan minus. Pesannya menyebut jalan keluarnya — "ditolak" tanpa
     * arah hanya memindahkan kebuntuan ke petugas.
     */
    public static function swpAlreadyWithdrawn(string $loanNumber, string $toReverse, string $balance): self
    {
        return new self(sprintf(
            'Pinjaman %s tidak dapat dibatalkan: SWP-nya (Rp %s) sudah ditarik anggota — saldo SWP tersisa Rp %s. '
            .'Batalkan dulu pencairan SWP tersebut, baru pinjamannya.',
            $loanNumber,
            number_format((float) $toReverse, 0, ',', '.'),
            number_format((float) $balance, 0, ',', '.'),
        ));
    }
}
