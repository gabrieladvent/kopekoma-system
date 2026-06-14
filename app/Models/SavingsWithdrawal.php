<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SavingsWithdrawal extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'withdrawal_number',
        'idempotency_key',
        'member_id',
        'savings_type',
        'amount',
        'withdrawal_date',
        'related_loan_id',
        'notes',
        'is_reversal',
        'reversal_of_id',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'withdrawal_date' => 'date',
        'is_reversal' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function relatedLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'related_loan_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(SavingsWithdrawal::class, 'reversal_of_id');
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
