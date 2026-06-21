<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast nominal uang ke BILANGAN BULAT rupiah (int), bukan string desimal.
 *
 * Kolom uang disimpan `decimal(18,2)`, yang oleh Laravel dibaca sebagai string
 * "150000.00". Bila string itu mengalir ke Filament MoneyInput lewat $set()
 * (mis. prefill di repeater / afterStateUpdated) — jalur yang melewati
 * formatStateUsing — maka stripCharacters('.') saat submit menghapus titik
 * desimalnya dan nominal jadi 100x ("150000.00" -> "15000000").
 *
 * Dengan cast ini, atribut SELALU dibaca sebagai int bersih (150000) sehingga
 * konversi (string) tak pernah menghasilkan titik. Guard di sumber data ini
 * menutup seluruh kelas bug tersebut untuk SEMUA pemakai field, bukan tambal
 * per-form. Aplikasi memakai rupiah penuh (mask scale 0, tanpa sen), jadi
 * membuang pecahan aman.
 *
 * @implements CastsAttributes<int|null, int|float|string|null>
 */
class WholeRupiah implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return ($value === null || $value === '') ? null : (int) round((float) $value);
    }
}
