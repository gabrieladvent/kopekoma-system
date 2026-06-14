<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Installment extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'installment_number',
        'idempotency_key',
        'loan_id',
        'schedule_id',
        'installment_seq',
        'payment_date',
        'due_date',
        'principal_paid',
        'interest_paid',
        'time_deposit_saved',
        'amount_paid',
        'remaining_principal',
        'payment_method',
        'is_reversal',
        'reversal_of_id',
        'recorded_by',
    ];

    protected $casts = [
        'installment_seq' => 'integer',
        'payment_date' => 'date',
        'due_date' => 'date',
        'principal_paid' => 'decimal:2',
        'interest_paid' => 'decimal:2',
        'time_deposit_saved' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'remaining_principal' => 'decimal:2',
        'is_reversal' => 'boolean',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'schedule_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Installment::class, 'reversal_of_id');
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
