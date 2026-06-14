<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberHolidaySaving extends Model
{
    protected $fillable = [
        'member_id',
        'period_year',
        'monthly_amount',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'monthly_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
