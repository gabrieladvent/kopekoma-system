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
        'disbursement_method',
        'disbursement_bank',
        'disbursement_account_number',
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

    /**
     * Konstanta angsuran (monthly_*) dikunci saat akad — breakdown & saldo
     * historis bergantung padanya (ADR 2026-06-26 D6). Tolak perubahannya
     * begitu ada angsuran tercatat agar sejarah tak bergeser retroaktif.
     */
    protected static function booted(): void
    {
        static::updating(function (self $loan): void {
            $locked = ['monthly_principal', 'monthly_interest', 'monthly_time_deposit'];

            $changingLocked = collect($locked)->some(fn (string $col): bool => $loan->isDirty($col));

            if ($changingLocked && $loan->installments()->exists()) {
                throw new \RuntimeException(
                    'Konstanta angsuran (monthly_*) tidak boleh diubah setelah ada angsuran tercatat.'
                );
            }
        });
    }

    /**
     * Sisa pokok = `principal_amount − (jumlah angsuran terbayar × monthly_principal)`,
     * floor 0 (ADR 2026-06-26 D2). Count-based net reversal — satu sumber sisa pokok
     * untuk progres, kuitansi, & laporan; menggantikan kolom `remaining_principal`.
     */
    public function remainingPrincipal(): string
    {
        $netCount = Installment::query()
            ->where('loan_id', $this->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END), 0) as net')
            ->value('net');

        $paid = bcmul((string) $this->monthly_principal, (string) (int) $netCount, 2);
        $remaining = bcsub((string) $this->principal_amount, $paid, 2);

        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }

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
