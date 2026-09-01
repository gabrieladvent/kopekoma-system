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

    /**
     * Tagihan KONTRAK satu bulan = Σ konstanta akad. Seragam di seluruh baris
     * jadwal: `buildSchedule()` menulis `total_due` dari konstanta yang sama, dan
     * tak ada jalur yang meng-update baris jadwal setelah dibuat (ADR 2026-08-28,
     * "Verifikasi asumsi"). Karena itu ia boleh dihitung dari loan, tanpa join.
     *
     * Ini angka yang dipakai Titipan Pokok sebagai patokan — BUKAN tagihan
     * efektif. Lihat overpaymentCredit().
     */
    public function monthlyTotal(): string
    {
        return bcadd(
            bcadd((string) $this->monthly_principal, (string) $this->monthly_interest, 2),
            (string) $this->monthly_time_deposit,
            2
        );
    }

    /**
     * Saldo Titipan Pokok pinjaman ini (ADR 2026-08-28) — DITURUNKAN dari riwayat
     * angsuran, tidak disimpan di kolom mana pun:
     *
     *     titipan = Σ_signed(amount_paid) − netCount × monthlyTotal()
     *
     * Konvensi tanda dan pengecualian baris pelunasan mengikuti settledPrincipal()
     * persis. Karena diturunkan, ia otomatis benar setelah pembatalan — tak ada
     * kolom yang bisa menyimpang diam-diam (Alternatives Considered: kolom saldo
     * di `loans` ditolak justru karena bisa jadi minus tanpa jejak).
     *
     * PATOKANNYA TAGIHAN KONTRAK, BUKAN TAGIHAN EFEKTIF. Memakai tagihan efektif
     * menghitung ganda titipan yang sudah dipotong dan membuat saldonya membengkak
     * tiap bulan — kekeliruan v2 ADR ini, dijaga test invariant (item 3f).
     *
     * Lunas / Dibatalkan ⇒ 0.00. Untuk Lunas ini pasangan dari pelimpahan sisa
     * titipan ke Simpanan Sukarela saat pinjaman ditutup (item 1h); untuk
     * Dibatalkan ia menjaga R18 — jadwalnya sudah dihapus, jadi titipan yang
     * tersisa di sana tak akan pernah bisa dimakan atau dilimpahkan siapa pun.
     *
     * TIDAK di-floor ke 0: nilai negatif berarti sebuah pembatalan menghapus
     * titipan yang sudah terpakai, dan justru itu yang harus terlihat supaya guard
     * di LoanPaymentService::reverse() (item 1j) bisa menolaknya. Pada state yang
     * sudah ter-commit, guard itulah yang menjamin hasilnya ≥ 0.
     *
     * HANYA BARIS MILIK FITUR INI YANG DIHITUNG (`credit_applied` non-NULL) —
     * R21/OQ-10. Sebelum fitur ini, `pay()` menyimpan SELURUH uang yang diterima
     * di `amount_paid` lalu mengkreditkan kelebihannya ke Simpanan Sukarela lewat
     * transaksi terpisah. Tanpa saringan ini, setiap kelebihan bayar lama muncul
     * lagi sebagai titipan dan anggota menerima keringanan yang sama dua kali —
     * uangnya sudah diserahkan sebagai simpanan, lalu dipotongkan sekali lagi dari
     * tagihannya. Baris lama yang bayar pas berkontribusi nol, jadi menyaringnya
     * tidak mengubah apa pun; yang kurang bayar tak pernah ada karena ditolak
     * `belowBill()`.
     *
     * `credit_applied` layak jadi penandanya karena ia ditulis sekali saat baris
     * dibuat, tak pernah di-UPDATE, dan ikut disalin `Installment::reverseClone()`
     * — jadi penandanya stabil dan tetap benar setelah pembatalan. Konsekuensinya:
     * **setiap jalur yang membuat baris angsuran WAJIB mengisi `credit_applied`**
     * (0 bila tak memakai titipan, bukan NULL), atau barisnya hilang diam-diam
     * dari saldo. Hari ini pembuatnya hanya LoanPaymentService::pay() dan
     * settleEarly(); dijaga test.
     */
    public function overpaymentCredit(): string
    {
        if (in_array($this->status, [LoanStatus::Lunas, LoanStatus::Dibatalkan], true)) {
            return '0.00';
        }

        $totals = Installment::query()
            ->where('loan_id', $this->id)
            ->where('is_settlement', false)
            ->whereNotNull('credit_applied')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount_paid ELSE -amount_paid END), 0) as paid')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END), 0) as net')
            ->first();

        $paid = bcadd((string) ($totals->paid ?? '0'), '0', 2);
        $billed = bcmul($this->monthlyTotal(), (string) (int) ($totals->net ?? 0), 2);

        return bcsub($paid, $billed, 2);
    }

    /**
     * Tagihan EFEKTIF satu angsuran (ADR 2026-08-28) — **satu-satunya sumber**:
     *
     *     tagihan_efektif = total_due − min(titipan, principal_due)
     *
     * Titipan hanya boleh memotong komponen POKOK. Kalau ia boleh menghapus satu
     * jadwal utuh, jumlah angsuran berkurang → jasa tertagih koperasi berkurang
     * DAN hak Tabungan Berjangka anggota hangus (akrualnya count-based). Batas
     * `principal_due` itulah yang menjaga keduanya, jadi jangan dilonggarkan.
     *
     * Angka kontraknya dibaca dari baris jadwal, bukan dari konstanta loan —
     * keduanya identik (`buildSchedule()` menulis `total_due` dari konstanta yang
     * sama), tapi baris jadwal adalah kontrak untuk bulan itu.
     *
     * Saldo titipan di-floor 0 di sini: saldo negatif — keadaan sementara yang
     * hanya bisa muncul lewat pembatalan yang seharusnya ditolak guard reverse()
     * — tidak boleh sampai MENAIKKAN tagihan anggota di atas kontraknya.
     */
    public function effectiveBill(InstallmentSchedule $schedule): string
    {
        return $this->effectiveBillWithCredit($schedule, $this->overpaymentCredit());
    }

    /**
     * Varian yang menerima saldo titipan dari luar. Dipakai `allocate()` saat
     * MENSIMULASIKAN beberapa angsuran dalam satu setoran: saldo di database
     * belum bergerak sampai barisnya benar-benar dibuat, jadi angsuran kedua dan
     * seterusnya harus dihitung dengan saldo berjalan hasil simulasi — bukan
     * dengan saldo lama yang dibaca `overpaymentCredit()`.
     *
     * Rumusnya tetap hidup di satu tempat: `effectiveBill()` hanyalah metode ini
     * dengan saldo yang dibaca dari database.
     */
    public function effectiveBillWithCredit(InstallmentSchedule $schedule, string $credit): string
    {
        if ($schedule->loan_id !== $this->id) {
            throw new \InvalidArgumentException(
                'Jadwal angsuran bukan milik pinjaman ini — tagihan efektif tidak bisa dihitung.'
            );
        }

        if (bccomp($credit, '0', 2) < 0) {
            $credit = '0.00';
        }

        $principalDue = bcadd((string) $schedule->principal_due, '0', 2);

        $applied = bccomp($credit, $principalDue, 2) < 0 ? $credit : $principalDue;

        return bcsub((string) $schedule->total_due, $applied, 2);
    }

    /**
     * Jumlah Pelunasan Dipercepat (ADR 2026-07-22 + 2026-08-28) — **satu-satunya
     * sumber**:
     *
     *     payoff = settledPrincipal() + 1× monthly_interest − min(titipan, settledPrincipal())
     *
     * Jasa bulan sisa dibebaskan; yang ditagih hanya 1× jasa. Rumus ini sebelumnya
     * ditulis ulang di dua tempat (`settleEarly()` dan validasi batch) — R2:
     * perbaikan yang cuma dipasang di satu jalur membuat jalur satunya menagih
     * dobel, dan anggota rugi tanpa ada yang sadar. Jangan disalin lagi.
     *
     * Titipan dipotong dengan batas `settledPrincipal()` — konsisten dengan
     * effectiveBill() yang membatasi di `principal_due`: Titipan **Pokok** hanya
     * membayar pokok, tak pernah menggerus jasa. Sisa titipan di atas batas itu
     * tidak hilang — ia dilimpahkan ke Simpanan Sukarela saat pinjaman jadi Lunas.
     *
     * Ambang penjaga "arahkan ke Pelunasan Dipercepat" memakai angka INI, bukan
     * sisa kontrak mentah: dibandingkan terhadap sisa mentah, anggota bertitipan
     * tak akan pernah terdeteksi dan justru kehilangan pembebasan jasanya.
     */
    public function payoffAmount(): string
    {
        $payoff = bcsub(
            bcadd($this->settledPrincipal(), (string) $this->monthly_interest, 2),
            $this->payoffCreditApplied(),
            2
        );

        return bccomp($payoff, '0', 2) < 0 ? '0.00' : $payoff;
    }

    /**
     * Titipan yang benar-benar TERPAKAI oleh pelunasan = `min(titipan, sisa
     * pokok)`. Angka inilah yang ditulis ke `credit_applied` baris pelunasan
     * (item 1i), dan ia wajib berasal dari sini — bukan dihitung ulang di
     * service — agar potongan yang ditagihkan dan potongan yang dicatat tak
     * pernah bisa berbeda.
     *
     * Selisih `overpaymentCredit() − payoffCreditApplied()` adalah titipan yang
     * TIDAK terpakai; ia dilimpahkan ke Simpanan Sukarela saat pinjaman ditutup,
     * bukan hangus.
     */
    public function payoffCreditApplied(): string
    {
        $principal = $this->settledPrincipal();

        $credit = $this->overpaymentCredit();

        if (bccomp($credit, '0', 2) < 0) {
            $credit = '0.00';
        }

        return bccomp($credit, $principal, 2) < 0 ? $credit : $principal;
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
