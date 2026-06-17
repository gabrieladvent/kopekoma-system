<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasSignedAmount
{
    public function scopeSignedAmount(Builder $query): Builder
    {
        return $query->selectRaw(
            'COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net'
        );
    }
}
