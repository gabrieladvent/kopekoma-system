<?php

namespace App\Models;

use App\Enums\LoanStatus;
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
        'status' => LoanStatus::class,
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
     * Sisa pokok LOAN saat ini — "berapa yang masih ditanggung". Count-based net
     * reversal (ADR 2026-06-26 D2), TAPI di-gate untuk Pelunasan Dipercepat
     * (ADR 2026-07-22): begitu ada pelunasan aktif, loan lunas → sisa 0. Dipakai
     * progres, badge, laporan. Untuk breakdown/kuitansi baris pelunasan, pakai
     * settledPrincipal() (non-gated) — bukan ini.
     */
    public function remainingPrincipal(): string
    {
        if ($this->hasActiveSettlement()) {
            return '0.00';
        }

        return $this->settledPrincipal();
    }

    /**
     * Pokok yang DITUTUP oleh pelunasan = `principal_amount − (jumlah angsuran
     * NORMAL terbayar-net × monthly_principal)`, floor 0. NON-GATED: tetap benar
     * walau loan sudah Lunas (baris settlement `is_settlement=1` dikecualikan dari
     * count). Sumber angka "Pokok" pada breakdown() baris pelunasan (ADR 2026-07-22).
     */
    public function settledPrincipal(): string
    {
        $netCount = Installment::query()
            ->where('loan_id', $this->id)
            ->where('is_settlement', false)
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END), 0) as net')
            ->value('net');

        $paid = bcmul((string) $this->monthly_principal, (string) (int) $netCount, 2);
        $remaining = bcsub((string) $this->principal_amount, $paid, 2);

        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }

    /**
     * Ada pelunasan dipercepat aktif? NET-AWARE (ADR 2026-07-22): baris settlement
     * asli TIDAK dihapus saat di-reverse (ReverseTransaction hanya menyisipkan
     * baris-lawan `is_reversal=1`), jadi keberadaan baris non-reversal saja tak
     * cukup — hitung net (settlement terpasang − settlement dibalik) > 0.
     */
    public function hasActiveSettlement(): bool
    {
        $net = Installment::query()
            ->where('loan_id', $this->id)
            ->where('is_settlement', true)
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END), 0) as net')
            ->value('net');

        return (int) $net > 0;
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
        return $query->where('status', LoanStatus::Cair);
    }

    public function isLunas(): bool
    {
        return $this->status === LoanStatus::Lunas;
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
