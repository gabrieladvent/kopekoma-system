<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

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
