<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SavingsDeposit extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'transaction_number',
        'idempotency_key',
        'member_id',
        'savings_type',
        'amount',
        'deposit_date',
        'period_month',
        'deposit_method',
        'deposited_by',
        'reference_number',
        'notes',
        'is_reversal',
        'reversal_of_id',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'deposit_date' => 'date',
        'period_month' => 'date',
        'is_reversal' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(SavingsDeposit::class, 'reversal_of_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
