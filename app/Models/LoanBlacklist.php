<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanBlacklist extends Model
{
    protected $fillable = [
        'member_id',
        'reason',
        'is_active',
        'blacklisted_at',
        'released_at',
        'recorded_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'blacklisted_at' => 'date',
        'released_at' => 'date',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
