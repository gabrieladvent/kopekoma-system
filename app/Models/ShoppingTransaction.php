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

class ShoppingTransaction extends Model implements Reversible
{
    use GeneratesTransactionNumber;
    use HasFactory;
    use HasSignedAmount;
    use HasUuids;
    use LogsActivity;

    protected $fillable = [
        'idempotency_key',
        'idempotency_hash',
        'transaction_number',
        'member_id',
        'amount',
        'transaction_date',
        'source',
        'store_client_id',
        'reference_number',
        'notes',
        'is_reversal',
        'reversal_of_id',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'is_reversal' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(ShoppingTransaction::class, 'reversal_of_id');
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
        return 'BLJ';
    }

    public function reverseClone(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $this->member_id,
            'amount' => $this->amount,
            'transaction_date' => $this->transaction_date,
            'source' => $this->source,
            // Pertahankan atribusi toko pada baris refund store_api (ADR D6/D8).
            'store_client_id' => $this->store_client_id,
        ];
    }
}
