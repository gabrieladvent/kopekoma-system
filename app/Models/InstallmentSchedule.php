<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'installment_seq',
        'due_date',
        'principal_due',
        'interest_due',
        'time_deposit_due',
        'total_due',
        'status',
    ];

    protected $casts = [
        'installment_seq' => 'integer',
        'due_date' => 'date',
        'principal_due' => 'decimal:2',
        'interest_due' => 'decimal:2',
        'time_deposit_due' => 'decimal:2',
        'total_due' => 'decimal:2',
    ];

    /**
     * Angsuran terlewat: jatuh tempo sudah lewat tapi belum terbayar (ADR D10).
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'Belum Bayar')
            ->whereDate('due_date', '<', now()->toDateString());
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class, 'schedule_id');
    }
}
