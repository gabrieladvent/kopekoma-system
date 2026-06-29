<?php

namespace App\Models;

use App\Contracts\Reversible;
use App\Models\Concerns\GeneratesTransactionNumber;
use App\Models\Concerns\HasSignedAmount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SavingsWithdrawal extends Model implements Reversible
{
    use GeneratesTransactionNumber;
    use HasFactory;
    use HasSignedAmount;
    use HasUuids;
    use LogsActivity;

    protected $fillable = [
        'withdrawal_number',
        'idempotency_key',
        'member_id',
        'savings_type',
        'amount',
        'withdrawal_date',
        'status',
        'approved_by',
        'approved_at',
        'disbursed_at',
        'period_year',
        'related_loan_id',
        'disbursement_method',
        'notes',
        'is_reversal',
        'reversal_of_id',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'withdrawal_date' => 'date',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'period_year' => 'integer',
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

    /** Baris-lawan reversal yang menunjuk record ini (≤ 1, `reversal_of_id` unik). */
    public function reversal(): HasOne
    {
        return $this->hasOne(SavingsWithdrawal::class, 'reversal_of_id');
    }

    /** Sudah pernah di-reversal? (mencegah reversal ganda + sembunyikan tombol). */
    public function isReversed(): bool
    {
        return $this->relationLoaded('reversal')
            ? $this->reversal !== null
            : $this->reversal()->exists();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function transactionNumberColumn(): string
    {
        return 'withdrawal_number';
    }

    public function transactionNumberPrefix(): string
    {
        return 'TRK';
    }

    public function reverseClone(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $this->member_id,
            'savings_type' => $this->savings_type,
            'amount' => $this->amount,
            'withdrawal_date' => $this->withdrawal_date,
            'status' => $this->status,
            'period_year' => $this->period_year,
            'related_loan_id' => $this->related_loan_id,
            'disbursement_method' => $this->disbursement_method,
        ];
    }
}
