<?php

namespace App\Exceptions;

use RuntimeException;

class UnsupportedSavingsType extends RuntimeException
{
    public static function forType(string $type): self
    {
        return new self("Jenis simpanan '{$type}' tidak didukung di modul Simpanan (sumber dari modul Pinjaman).");
    }
}
