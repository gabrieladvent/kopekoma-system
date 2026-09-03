<?php

namespace App\Models;

use App\Contracts\Reversible;
use App\Enums\LoanStatus;
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
        'session_key',
        'loan_id',
        'schedule_id',
        'installment_seq',
        'payment_date',
        'due_date',
        'amount_paid',
        'credit_applied',
        'payment_method',
        'notes',
        'is_reversal',
        'is_settlement',
        'reversal_of_id',
        'recorded_by',
    ];

    /** breakdown() dipanggil berkali-kali per record oleh infolist Filament. */
    private ?array $breakdownCache = null;

    protected $casts = [
        'installment_seq' => 'integer',
        'payment_date' => 'date',
        'due_date' => 'date',
        'amount_paid' => 'decimal:2',
        'credit_applied' => 'decimal:2',
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
     * tidak disimpan. Pokok/Jasa/Tab dari konstanta loan.
     *
     * Kunci `other` dicabut di ADR 2026-08-28: uang itu bukan lagi "Kelebihan
     * Bayar" yang berangkat ke Simpanan Sukarela, melainkan **Titipan Pokok
     * disisihkan** yang mengendap di pinjaman (R12 — nama lama dipakai untuk arti
     * berbeda dan menyesatkan pengurus). Penggantinya `credit_reserved`,
     * berpasangan dengan `credit_applied` dan saldo `credit_balance`.
     *
     * @return array{principal:string, interest:string, time_deposit:string, credit_applied:string, credit_reserved:string, credit_balance:string, total:string}
     */
    public function breakdown(): array
    {
        return $this->breakdownCache ??= $this->computeBreakdown();
    }

    /**
     * Kuitansi WAJIB menutup di KEDUA arah (ADR 2026-08-28 item 1l, R16):
     *
     *     pokok + jasa + tab − dipakai + disisihkan = total diterima
     *
     * dengan `dipakai = max(0, kontrak − dibayar)` (dibaca dari `credit_applied`,
     * tidak dihitung ulang) dan `disisihkan = max(0, dibayar − kontrak)`. Persis
     * satu dari keduanya bisa bukan-nol pada satu baris, sehingga kuitansi selalu
     * berjumlah. Tanpa baris "disisihkan", nota multi-angsuran tidak menutup —
     * komponennya berjumlah 1.050.000 sementara totalnya 1.950.000.
     *
     * `credit_applied` NULL (baris pra-fitur) diperlakukan 0, sehingga baris lama
     * tetap menutup persis seperti sebelum fitur ini ada.
     *
     * @return array{principal:string, interest:string, time_deposit:string, credit_applied:string, credit_reserved:string, credit_balance:string, total:string}
     */
    private function computeBreakdown(): array
    {
        $loan = $this->loan;

        $creditApplied = $this->money($this->credit_applied);

        if ($this->is_settlement) {
            $principal = $this->money($loan?->settledPrincipal());
            $interest = $this->money($loan?->monthly_interest);
            $timeDeposit = '0.00';
            $components = bcadd($principal, $interest, 2);
        } else {
            $principal = $this->money($loan?->monthly_principal);
            $interest = $this->money($loan?->monthly_interest);
            $timeDeposit = $this->money($loan?->monthly_time_deposit);
            $components = bcadd(bcadd($principal, $interest, 2), $timeDeposit, 2);
        }

        // Yang benar-benar ditagihkan setelah titipan dipotong.
        $charged = bcsub($components, $creditApplied, 2);

        $reserved = bcsub($this->money($this->amount_paid), $charged, 2);

        if (bccomp($reserved, '0', 2) < 0) {
            $reserved = '0.00';
        }

        return [
            'principal' => $principal,
            'interest' => $interest,
            'time_deposit' => $timeDeposit,
            'credit_applied' => $creditApplied,
            'credit_reserved' => $reserved,
            'credit_balance' => $this->creditBalanceAfter(),
            'total' => $this->money($this->amount_paid),
        ];
    }

    /**
     * Saldo Titipan Pokok SETELAH baris ini — menjawab "kapan habis" pada
     * kuitansi (begitu 0, titipannya habis di setoran itu).
     *
     * Dihitung atas riwayat sampai baris ini saja. `installment_number` boleh
     * dipakai sebagai urutan karena ia berjalan monoton dan zero-padded
     * (`ANG-2026-000001`), jadi urutan leksikalnya sama dengan urutan waktu.
     */
    private function creditBalanceAfter(): string
    {
        $loan = $this->loan;

        if ($loan === null) {
            return '0.00';
        }

        // Saat pinjaman ditutup, sisa titipan dilimpahkan ke Simpanan Sukarela
        // (item 1h/1i) — jadi saldo setelah baris TERAKHIR memang 0. Tanpa aturan
        // ini kuitansi penutup menampilkan titipan yang sudah tidak ada lagi.
        $isLatest = ! static::query()
            ->where('loan_id', $loan->id)
            ->where('installment_number', '>', $this->installment_number)
            ->exists();

        if ($isLatest && in_array($loan->status, [LoanStatus::Lunas, LoanStatus::Dibatalkan], true)) {
            return '0.00';
        }

        $totals = static::query()
            ->where('loan_id', $loan->id)
            ->where('is_settlement', false)
            ->whereNotNull('credit_applied')
            ->where('installment_number', '<=', $this->installment_number)
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount_paid ELSE -amount_paid END), 0) as paid')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END), 0) as net')
            ->first();

        $balance = bcsub(
            $this->money($totals->paid ?? 0),
            bcmul($loan->monthlyTotal(), (string) (int) ($totals->net ?? 0), 2),
            2
        );

        return bccomp($balance, '0', 2) < 0 ? '0.00' : $balance;
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
            // Kelas bug yang sama (ADR 2026-08-28 item 1k): `credit_applied` adalah
            // penanda "baris milik fitur Titipan Pokok", dan Loan::overpaymentCredit()
            // hanya menghitung baris ber-penanda. Baris-lawan ber-NULL tersaring
            // keluar, sehingga pembatalan TIDAK memulihkan saldo titipan — uang
            // anggota tertinggal di layar sebagai titipan yang tak pernah ada.
            'credit_applied' => $this->credit_applied,
            // Penanda sesi ikut agar layar pembatalan tetap bisa menampilkan
            // keterkaitan ("satu setoran bersama ANG-…") pada baris-lawan.
            'session_key' => $this->session_key,
        ];
    }
}
