<?php

namespace App\Models;

use App\Contracts\Reversible;
use App\Models\Concerns\GeneratesTransactionNumber;
use App\Models\Concerns\HasSignedAmount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SavingsDeposit extends Model implements Reversible
{
    use GeneratesTransactionNumber;
    use HasFactory;
    use HasSignedAmount;
    use HasUuids;
    use LogsActivity;

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

    public function transactionNumberColumn(): string
    {
        return 'transaction_number';
    }

    public function transactionNumberPrefix(): string
    {
        return 'STR';
    }

    public function reverseClone(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $this->member_id,
            'savings_type' => $this->savings_type,
            'amount' => $this->amount,
            'deposit_date' => $this->deposit_date,
            'period_month' => $this->period_month,
            'deposit_method' => $this->deposit_method,
            'deposited_by' => $this->deposited_by,
        ];
    }
}
