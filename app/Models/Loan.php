<?php

namespace App\Models;

use App\Models\Concerns\GeneratesTransactionNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Loan extends Model implements HasMedia
{
    use GeneratesTransactionNumber;
    use HasFactory;
    use HasUuids;
    use InteractsWithMedia;
    use LogsActivity;

    protected $fillable = [
        'loan_number',
        'member_id',
        'loan_type',
        'principal_amount',
        'admin_fee',
        'swp_amount',
        'disbursed_amount',
        'term_months',
        'monthly_principal',
        'monthly_interest',
        'monthly_time_deposit',
        'disbursement_date',
        'first_due_date',
        'status',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'admin_fee' => 'decimal:2',
        'swp_amount' => 'decimal:2',
        'disbursed_amount' => 'decimal:2',
        'term_months' => 'integer',
        'monthly_principal' => 'decimal:2',
        'monthly_interest' => 'decimal:2',
        'monthly_time_deposit' => 'decimal:2',
        'disbursement_date' => 'date',
        'first_due_date' => 'date',
    ];

    public function transactionNumberColumn(): string
    {
        return 'loan_number';
    }

    public function transactionNumberPrefix(): string
    {
        return 'PJM';
    }

    /**
     * Pinjaman yang masih berjalan (belum lunas).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Cair');
    }

    public function isLunas(): bool
    {
        return $this->status === 'Lunas';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
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
