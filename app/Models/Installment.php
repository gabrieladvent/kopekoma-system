<?php

namespace App\Models;

use App\Contracts\Reversible;
use App\Models\Concerns\GeneratesTransactionNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Installment extends Model implements HasMedia, Reversible
{
    use GeneratesTransactionNumber;
    use HasFactory;
    use HasUuids;
    use InteractsWithMedia;
    use LogsActivity;

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
        'notes',
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

    /**
     * Alias `amount` → `amount_paid` agar mekanisme reversal generik
     * (ReverseTransaction yang me-log `$model->amount`) tetap konsisten.
     */
    protected function amount(): Attribute
    {
        return Attribute::get(fn () => $this->amount_paid);
    }

    public function transactionNumberColumn(): string
    {
        return 'installment_number';
    }

    public function transactionNumberPrefix(): string
    {
        return 'ANG';
    }

    /**
     * Net Tabungan Berjangka (terbayar − reversal) dari pembayaran aktual.
     * Dipakai SavingsBalanceService untuk saldo `tabungan_berjangka` (ADR D7).
     */
    public function scopeSignedTimeDeposit(Builder $query): Builder
    {
        return $query->selectRaw(
            'COALESCE(SUM(CASE WHEN is_reversal = 0 THEN time_deposit_saved ELSE -time_deposit_saved END), 0) as net'
        );
    }

    public function registerMediaCollections(): void
    {
        // Bukti pembayaran angsuran (slip/foto/kuitansi) — ADR D5.
        $this->addMediaCollection('bukti')->singleFile();
    }

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

    public function reverseClone(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'loan_id' => $this->loan_id,
            'schedule_id' => $this->schedule_id,
            'installment_seq' => $this->installment_seq,
            'payment_date' => $this->payment_date,
            'due_date' => $this->due_date,
            'principal_paid' => $this->principal_paid,
            'interest_paid' => $this->interest_paid,
            'time_deposit_saved' => $this->time_deposit_saved,
            'amount_paid' => $this->amount_paid,
            'remaining_principal' => $this->remaining_principal,
            'payment_method' => $this->payment_method,
        ];
    }
}
