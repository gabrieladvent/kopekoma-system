<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Nama file penyimpanan untuk media unggahan.
 *
 * Nama asli dari klien TIDAK dipakai sebagai nama file di disk. Nama seperti
 * "kartu-anggota-KM-2026-0002.pdf" dapat ditebak — nomor anggota dan nomor
 * pinjaman berurutan — sehingga siapa pun bisa mengenumerasi berkas bila suatu
 * saat disk media salah dikonfigurasi menjadi publik. ULID acak memutus itu,
 * sekaligus menghindari path traversal dan tabrakan nama.
 *
 * Nama asli tetap disimpan sebagai `name` (lewat usingName) untuk ditampilkan.
 */
class MediaFileName
{
    public static function for(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $extension === ''
            ? (string) Str::ulid()
            : Str::ulid().'.'.$extension;
    }
}
