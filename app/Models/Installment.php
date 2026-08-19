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
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'amount_paid',
        'payment_method',
        'notes',
        'is_reversal',
        'is_settlement',
        'reversal_of_id',
        'recorded_by',
    ];

    protected $casts = [
        'installment_seq' => 'integer',
        'payment_date' => 'date',
        'due_date' => 'date',
        'amount_paid' => 'decimal:2',
        'is_reversal' => 'boolean',
        'is_settlement' => 'boolean',
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
     * Net Tabungan Berjangka (terbayar − reversal) = jumlah angsuran terbayar ×
     * konstanta `loans.monthly_time_deposit` (ADR 2026-06-26 D5, count-based).
     * Join ke `loans` agar konstanta per-pinjaman ikut saat dijumlah lintas
     * pinjaman anggota. Pemanggil filter via `installments.loan_id` /
     * `loans.member_id` (jangan join `loans` lagi — sudah di sini).
     */
    public function scopeSignedTimeDeposit(Builder $query): Builder
    {
        return $query
            ->join('loans', 'installments.loan_id', '=', 'loans.id')
            ->where('installments.is_settlement', false)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN installments.is_reversal = 0 THEN loans.monthly_time_deposit ELSE -loans.monthly_time_deposit END), 0) as net'
            );
    }

    /**
     * Rincian pembayaran untuk nota/kuitansi (ADR 2026-06-26 D3) — DIHITUNG,
     * tidak disimpan. Pokok/Jasa/Tab dari konstanta loan; "Kelebihan Bayar" =
     * kelebihan `amount_paid` atas tagihan (Σ konstanta), floor 0.
     *
     * @return array{principal:string, interest:string, time_deposit:string, other:string, total:string}
     */
    public function breakdown(): array
    {
        $loan = $this->loan;

        if ($this->is_settlement) {
            $principal = $this->money($loan?->settledPrincipal());

            $interest = $this->money($loan?->monthly_interest);

            $timeDeposit = '0.00';

            $payoff = bcadd($principal, $interest, 2);

            $other = bcsub($this->money($this->amount_paid), $payoff, 2);

            if (bccomp($other, '0', 2) < 0) {
                $other = '0.00';
            }

            return [
                'principal' => $principal,
                'interest' => $interest,
                'time_deposit' => $timeDeposit,
                'other' => $other,
                'total' => $this->money($this->amount_paid),
            ];
        }

        $principal = $this->money($loan?->monthly_principal);
        $interest = $this->money($loan?->monthly_interest);
        $timeDeposit = $this->money($loan?->monthly_time_deposit);
        $bill = bcadd(bcadd($principal, $interest, 2), $timeDeposit, 2);

        $other = bcsub($this->money($this->amount_paid), $bill, 2);
        if (bccomp($other, '0', 2) < 0) {
            $other = '0.00';
        }

        return [
            'principal' => $principal,
            'interest' => $interest,
            'time_deposit' => $timeDeposit,
            'other' => $other,
            'total' => $this->money($this->amount_paid),
        ];
    }

    private function money(string|int|float|null $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
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

    /** Baris-lawan reversal yang menunjuk record ini (≤ 1, `reversal_of_id` unik). */
    public function reversal(): HasOne
    {
        return $this->hasOne(Installment::class, 'reversal_of_id');
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
            'amount_paid' => $this->amount_paid,
            'payment_method' => $this->payment_method,
            // Baris-lawan pelunasan HARUS ikut bertanda settlement (ADR 2026-07-22):
            // load-bearing untuk hasActiveSettlement()/settledPrincipal()/
            // signedTimeDeposit yang net-aware — tanpa ini, reverse tak pulih benar.
            'is_settlement' => $this->is_settlement,
        ];
    }
}
