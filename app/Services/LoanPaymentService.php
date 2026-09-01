<?php

namespace App\Services;

use App\Actions\ReverseTransaction;
use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\CannotProcessPayment;
use App\Exceptions\CannotReverseTransaction;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class LoanPaymentService
{
    private const SCALE = 2;

    /** Sumber dana angsuran = debit saldo simpanan (ADR 2026-07-22). */
    private const SOURCE_SAVINGS = 'saldo_simpanan';

    /** Hanya Sukarela yang boleh didebit (hard — bukan mirror WITHDRAWABLE_TYPES). */
    private const DEBIT_SAVINGS_TYPE = 'sukarela';

    public function __construct(
        private readonly ReverseTransaction $reverse,
        private readonly WithdrawalWorkflow $workflow,
        private readonly SavingsBalanceService $balances,
    ) {}

    /**
     * Catat pembayaran angsuran (ADR 2026-08-28). Atomic.
     *
     * Satu setoran bisa menutup BEBERAPA angsuran bila petugas memilih mode
     * `tutup_sekalian`; barisnya dibuat di sini, rencananya disusun `allocate()`.
     * Mengembalikan baris untuk `$schedule` — baris pertama sesi. Baris saudaranya
     * dicari lewat `session_key`, bukan lewat nilai kembalian, agar kontrak lama
     * pemanggil tidak berubah.
     *
     * @param  array{amount_paid:string|int|float, payment_method?:string, payment_date?:string, idempotency_key?:string, mode?:string}  $input
     */
    public function pay(
        InstallmentSchedule $schedule,
        array $input,
        ?int $causerId = null,
        ?UploadedFile $bukti = null,
    ): Installment {
        $causerId ??= auth()->id();

        $fromSavings = ($input['payment_method'] ?? null) === self::SOURCE_SAVINGS;

        return DB::transaction(function () use ($schedule, $input, $causerId, $bukti, $fromSavings): Installment {
            // Debit simpanan: lock member DULU (urutan global member→loan→schedule),
            // konsisten dengan WithdrawalWorkflow::disburse agar tak deadlock.
            if ($fromSavings) {
                $memberId = Loan::query()->whereKey($schedule->loan_id)->value('member_id');
                Member::query()->lockForUpdate()->findOrFail($memberId);
            }

            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($schedule->loan_id);

            /** @var InstallmentSchedule $schedule */
            $schedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());

            if ($loan->status !== LoanStatus::Cair) {
                throw CannotProcessPayment::loanNotActive();
            }

            $amountPaid = $this->money($input['amount_paid']);

            if ($fromSavings) {
                // Otoritas Pengurus + atribusi (ADR §Design) — enforce di service
                // (defense-in-depth), bukan hanya di entry point Livewire.
                Gate::forUser($this->actingUser($causerId))->authorize('pay_installment_from_savings');

                // Consent WAJIB (server-side) — satu-satunya pengganti mata-kedua.
                if (! $bukti instanceof UploadedFile) {
                    throw CannotProcessPayment::consentRequired();
                }
            }

            // Alokasi DIHITUNG ULANG di dalam lock (ADR §Kunci transaksi) — payload
            // Livewire maupun pratinjau dialog tidak dipercaya. Di sinilah lantai
            // `belowBill()` (kini bertumpu tagihan EFEKTIF), penjaga Pelunasan
            // Dipercepat, dan penolakan jadwal basi ditegakkan.
            $plan = $this->allocate($loan, $schedule, $amountPaid, $input['mode'] ?? self::MODE_TITIPAN);

            if ($fromSavings) {
                // Dikunci tepat-tagihan: cegah lingkaran debit sukarela → kelebihan
                // balik ke sukarela. Patokannya tagihan EFEKTIF, yang bisa lebih
                // kecil dari kontrak bila anggota punya Titipan Pokok.
                if (bccomp($amountPaid, $loan->effectiveBill($schedule), self::SCALE) > 0) {
                    throw CannotProcessPayment::savingsMustEqualBill();
                }

                if (! $this->balances->canWithdraw($loan->member, self::DEBIT_SAVINGS_TYPE, $amountPaid)) {
                    throw CannotProcessPayment::insufficientSavings();
                }
            }

            // Kunci sesi + nomor urut, DITURUNKAN bukan diacak (ADR §Idempotensi):
            // klik simpan dua kali menghasilkan kunci yang sama persis lalu ditolak
            // indeks unik. Kunci acak per baris justru menghapus perlindungannya.
            $sessionKey = $input['idempotency_key'] ?? (string) Str::uuid();

            $installments = [];

            foreach ($plan['rows'] as $i => $row) {
                /** @var InstallmentSchedule $rowSchedule */
                $rowSchedule = $row['schedule'];

                $installments[] = Installment::create([
                    'idempotency_key' => $sessionKey.'-'.($i + 1),
                    'session_key' => $sessionKey,
                    'loan_id' => $loan->id,
                    'schedule_id' => $rowSchedule->getKey(),
                    'installment_seq' => $rowSchedule->installment_seq,
                    'payment_date' => $input['payment_date'] ?? now()->toDateString(),
                    'due_date' => $rowSchedule->due_date,
                    'amount_paid' => $row['amount_paid'],
                    'credit_applied' => $row['credit_applied'],
                    'payment_method' => $input['payment_method'] ?? 'manual',
                    'is_reversal' => false,
                    'recorded_by' => $causerId,
                ]);

                $rowSchedule->update(['status' => InstallmentScheduleStatus::Terbayar]);
            }

            $installment = $installments[0];

            // Bukti melekat di SETIAP baris sesi (ADR 2026-08-28 item 1g): baris
            // yang buktinya hanya menempel di baris pertama tampak tanpa bukti di
            // mata pemeriksa. `preservingOriginal()` WAJIB — `addMedia()` polos
            // MEMINDAHKAN berkas sumbernya, sehingga lampiran baris kedua gagal
            // dan seluruh transaksi ikut batal (R17).
            if ($bukti instanceof UploadedFile) {
                foreach ($installments as $created) {
                    $created->addMedia($bukti)->preservingOriginal()->toMediaCollection('bukti');
                }
            }

            if ($fromSavings) {
                $this->debitSavingsForInstallment($installment, $loan, $amountPaid, $causerId);
            }

            // Kelebihan bayar TIDAK lagi dikreditkan ke Sukarela di tengah masa
            // pinjaman — ia mengendap sebagai Titipan Pokok dan memotong pokok
            // angsuran berikutnya. Pelimpahan ke Sukarela hanya terjadi saat
            // pinjaman ditutup, di bawah.
            if (! $this->hasUnpaidSchedules($loan)) {
                $this->closeLoanWithRemainingCredit($loan, $installment, $causerId);
            }

            foreach ($installments as $created) {
                activity()
                    ->performedOn($created)
                    ->causedBy($causerId)
                    ->event('angsuran')
                    ->withProperties([
                        'loan_id' => $loan->id,
                        'amount_paid' => $created->amount_paid,
                        'seq' => $created->installment_seq,
                        'mode' => $plan['mode'],
                        'credit_applied' => $created->credit_applied,
                        'session_key' => $sessionKey,
                        'credit_before' => $plan['credit_before'],
                        'credit_after' => $plan['credit_after'],
                    ])
                    ->log("Pembayaran angsuran {$created->installment_number}");
            }

            return $installment;
        });
    }

    /**
     * Tutup pinjaman yang seluruh jadwalnya sudah terbayar (ADR 2026-08-28 item
     * 1h). Sisa Titipan Pokok dilimpahkan ke Simpanan Sukarela dan ditautkan ke
     * angsuran penutup — invariant *pinjaman Lunas selalu bertitipan 0*.
     *
     * Saldonya WAJIB dibaca sebelum status berubah: `overpaymentCredit()` menjawab
     * `0.00` begitu pinjaman berstatus Lunas, jadi membacanya setelah update
     * membuat uang anggota lenyap tanpa jejak.
     */
    private function closeLoanWithRemainingCredit(Loan $loan, Installment $closing, ?int $causerId): void
    {
        $leftover = $loan->overpaymentCredit();

        $loan->update(['status' => LoanStatus::Lunas]);

        if (bccomp($leftover, '0', self::SCALE) > 0) {
            $this->creditOverpaymentToSukarela($closing, $loan, $leftover, $causerId);
        }

        $this->createRefunds($loan, $causerId);
    }

    /** Bawaan: sisa uang disimpan sebagai Titipan Pokok. */
    public const MODE_TITIPAN = 'titipan';

    /** Petugas memilih menutup angsuran-angsuran berikutnya sekalian. */
    public const MODE_TUTUP_SEKALIAN = 'tutup_sekalian';

    /**
     * Alokasi bertingkat satu setoran (ADR 2026-08-28 item 1d) — MURNI HITUNGAN,
     * tidak menyentuh database. Urutannya:
     *
     *   1. Uang cukup melunasi SELURUH sisa pinjaman? → BERHENTI, arahkan ke
     *      Pelunasan Dipercepat. Berjalan PALING AWAL, mengalahkan bawaan maupun
     *      pilihan petugas: diproses diam-diam sebagai tutup-sekalian, anggota
     *      membayar jasa berlebih tanpa ada yang sadar.
     *   2. Tutup angsuran berjalan.
     *   3. Mode `titipan` (bawaan) → seluruh sisa jadi Titipan Pokok.
     *      Mode `tutup_sekalian` → ulangi (2) selama sisa uang ≥ tagihan efektif
     *      angsuran berikutnya.
     *   4. Sisa akhir → Titipan Pokok, diserap BARIS TERAKHIR.
     *
     * Saldo titipan disimulasikan di dalam loop — database belum bergerak sampai
     * barisnya dibuat, jadi tagihan efektif angsuran kedua dan seterusnya harus
     * dihitung dari saldo berjalan, bukan dari saldo lama. Itu sebabnya rumusnya
     * dipanggil lewat `effectiveBillWithCredit()`.
     *
     * @return array{mode:string, rows:list<array{schedule:InstallmentSchedule, amount_paid:string, credit_applied:string}>, credit_before:string, credit_after:string}
     */
    public function allocate(
        Loan $loan,
        InstallmentSchedule $start,
        string|int|float $amount,
        string $mode = self::MODE_TITIPAN,
    ): array {
        if (! in_array($mode, [self::MODE_TITIPAN, self::MODE_TUTUP_SEKALIAN], true)) {
            throw new \InvalidArgumentException("Mode alokasi tidak dikenal: {$mode}.");
        }

        if ($start->loan_id !== $loan->id) {
            throw new \InvalidArgumentException('Jadwal angsuran bukan milik pinjaman ini.');
        }

        if ($start->status === InstallmentScheduleStatus::Terbayar) {
            throw CannotProcessPayment::scheduleAlreadyPaid();
        }

        $amount = $this->money($amount);

        /** @var Collection<int, InstallmentSchedule> $unpaid */
        $unpaid = InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->where('installment_seq', '>=', $start->installment_seq)
            ->orderBy('installment_seq')
            ->get();

        // `$start` bisa saja model basi: statusnya BelumBayar di memori sementara
        // barisnya sudah Terbayar di database (setoran lain masuk saat form
        // terbuka). Tanpa cek ini, alokasi diam-diam bergeser ke jadwal
        // berikutnya dan petugas membayar angsuran yang bukan ia maksud.
        if ($unpaid->isEmpty() || $unpaid->first()->getKey() !== $start->getKey()) {
            throw CannotProcessPayment::scheduleAlreadyPaid();
        }

        // Penjaga Pelunasan Dipercepat. Dilewati saat hanya SATU jadwal tersisa:
        // di situ "pelunasan" tidak melunasi lebih awal apa pun — jasa yang
        // dibebaskan nol — sementara barisnya jadi `is_settlement` sehingga
        // angsuran terakhir berhenti mengakru Tabungan Berjangka anggota. Juga
        // dilewati untuk sebrakan, yang memang tidak bisa dilunasi dipercepat.
        if ($loan->loan_type === 'jangka_panjang' && $unpaid->count() >= 2) {
            $payoff = $loan->payoffAmount();

            if (bccomp($amount, $payoff, self::SCALE) >= 0) {
                throw CannotProcessPayment::shouldSettleEarly($payoff);
            }
        }

        $creditBefore = $loan->overpaymentCredit();
        $credit = bccomp($creditBefore, '0', self::SCALE) < 0 ? '0.00' : $creditBefore;

        $remaining = $amount;
        $rows = [];

        foreach ($unpaid as $schedule) {
            $bill = $loan->effectiveBillWithCredit($schedule, $credit);

            if ($rows === []) {
                // Angsuran berjalan wajib tertutup penuh — lantai anti-korupsi,
                // kini berdiri di tagihan EFEKTIF (ADR §OQ-0, risiko diterima).
                if (bccomp($remaining, $bill, self::SCALE) < 0) {
                    throw CannotProcessPayment::belowBill();
                }
            } elseif (bccomp($remaining, $bill, self::SCALE) < 0) {
                break;
            }

            $rows[] = ['schedule' => $schedule, 'amount_paid' => $bill];

            $remaining = bcsub($remaining, $bill, self::SCALE);

            // Δtitipan baris ini = dibayar − kontrak = −min(titipan, principal_due).
            $credit = bcadd($credit, bcsub($bill, (string) $schedule->total_due, self::SCALE), self::SCALE);

            if ($mode === self::MODE_TITIPAN) {
                break;
            }
        }

        // Baris terakhir menyerap sisa uang; sisanya itulah yang jadi titipan.
        $last = array_key_last($rows);
        $rows[$last]['amount_paid'] = bcadd($rows[$last]['amount_paid'], $remaining, self::SCALE);
        $credit = bcadd($credit, $remaining, self::SCALE);

        foreach ($rows as $i => $row) {
            $rows[$i]['credit_applied'] = $this->creditApplied($row['schedule'], $rows[$i]['amount_paid']);
        }

        return [
            'mode' => $mode,
            'rows' => $rows,
            'credit_before' => $creditBefore,
            'credit_after' => $credit,
        ];
    }

    /**
     * Titipan yang DIPAKAI baris ini = `max(0, tagihan kontrak − dibayar)`.
     *
     * Patokannya tagihan KONTRAK, bukan efektif. Memakai tagihan efektif
     * menghitung ganda titipan yang sudah dipotong dan membuat saldonya
     * membengkak tiap bulan — kekeliruan v2 ADR ini (R3).
     */
    private function creditApplied(InstallmentSchedule $schedule, string $amountPaid): string
    {
        $shortfall = bcsub((string) $schedule->total_due, $amountPaid, self::SCALE);

        return bccomp($shortfall, '0', self::SCALE) > 0 ? $shortfall : '0.00';
    }

    /**
     * Pelunasan Dipercepat (ADR 2026-07-22): tutup SELURUH sisa pinjaman sekaligus.
     * Jumlah pelunasan = sisa pokok + 1× jasa; jasa bulan sisa DIBEBASKAN, tabungan
     * berjangka masa depan tidak dipaksa. Satu baris `is_settlement=true` mewakili
     * penutupan (schedule_id & installment_seq null). Atomic.
     *
     * @param  array{amount_paid:string|int|float, payment_method?:string, payment_date?:string, idempotency_key?:string}  $input
     */
    public function settleEarly(
        Loan $loan,
        array $input,
        ?int $causerId = null,
        ?UploadedFile $bukti = null,
    ): Installment {
        $causerId ??= auth()->id();

        return DB::transaction(function () use ($loan, $input, $causerId, $bukti): Installment {
            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());

            if ($loan->status !== LoanStatus::Cair || $loan->loan_type !== 'jangka_panjang') {
                throw CannotProcessPayment::notSettleable();
            }

            /** @var Collection<int, InstallmentSchedule> $unpaid */
            $unpaid = InstallmentSchedule::query()
                ->where('loan_id', $loan->id)
                ->where('status', InstallmentScheduleStatus::BelumBayar)
                ->lockForUpdate()
                ->get();

            if ($unpaid->isEmpty()) {
                throw CannotProcessPayment::notSettleable();
            }

            $settledPrincipal = $loan->settledPrincipal();
            $interestCharged = $this->money($loan->monthly_interest);

            // Satu sumber (ADR 2026-08-28 item 1c) — sudah dikurangi Titipan Pokok.
            // Rumusnya JANGAN ditulis ulang di sini: versi lokal yang menyimpang
            // dari validasi batch adalah persis bentuk R2.
            $payoff = $loan->payoffAmount();

            // Dibaca SEBELUM status jadi Lunas — setelah itu overpaymentCredit()
            // menjawab 0.00 dan sisa titipan anggota lenyap tanpa jejak.
            $creditApplied = $loan->payoffCreditApplied();
            $creditLeftover = bcsub($loan->overpaymentCredit(), $creditApplied, self::SCALE);

            if (bccomp($creditLeftover, '0', self::SCALE) < 0) {
                $creditLeftover = '0.00';
            }

            $amountPaid = $this->money($input['amount_paid']);

            if (bccomp($amountPaid, $payoff, self::SCALE) < 0) {
                throw CannotProcessPayment::belowSettlement($payoff);
            }

            $installment = Installment::create([
                'idempotency_key' => $input['idempotency_key'] ?? (string) Str::uuid(),
                'loan_id' => $loan->id,
                'schedule_id' => null,
                'installment_seq' => null,
                'payment_date' => $input['payment_date'] ?? now()->toDateString(),
                'due_date' => $input['payment_date'] ?? now()->toDateString(),
                'amount_paid' => $amountPaid,
                // Jejak audit tidak boleh putus justru di transaksi TERBESAR
                // (item 1i). Angkanya datang dari payoffCreditApplied(), sumber
                // yang sama dengan potongan pada payoff — jadi yang ditagihkan
                // dan yang dicatat tak pernah bisa berbeda.
                'credit_applied' => $creditApplied,
                'payment_method' => $input['payment_method'] ?? 'manual',
                'is_reversal' => false,
                'is_settlement' => true,
                'recorded_by' => $causerId,
            ]);

            if ($bukti instanceof UploadedFile) {
                $installment->addMedia($bukti)->toMediaCollection('bukti');
            }

            InstallmentSchedule::query()
                ->whereIn('id', $unpaid->modelKeys())
                ->update(['status' => InstallmentScheduleStatus::Terbayar]);

            $loan->update(['status' => LoanStatus::Lunas]);

            // Satu setoran Sukarela untuk dua hal yang sama-sama jadi uang anggota:
            // kelebihan uang yang benar-benar diserahkan di loket, dan sisa Titipan
            // Pokok yang tak terpakai karena potongannya dibatasi sisa pokok.
            // Digabung agar pembalikannya juga satu — mesin reverseOverpaymentCredit()
            // menautkan lewat nomor angsuran, satu deposit per angsuran.
            $excess = bcsub($amountPaid, $payoff, self::SCALE);

            $toSukarela = bcadd(
                bccomp($excess, '0', self::SCALE) > 0 ? $excess : '0.00',
                $creditLeftover,
                self::SCALE
            );

            if (bccomp($toSukarela, '0', self::SCALE) > 0) {
                $this->creditOverpaymentToSukarela($installment, $loan, $toSukarela, $causerId);
            }

            $this->createRefunds($loan, $causerId);

            $waivedMonths = max(0, $unpaid->count() - 1);
            $interestWaived = bcmul($interestCharged, (string) $waivedMonths, self::SCALE);

            activity()
                ->performedOn($installment)
                ->causedBy($causerId)
                ->event('pelunasan_dipercepat')
                ->withProperties([
                    'loan_id' => $loan->id,
                    'amount_paid' => $amountPaid,
                    'settled_principal' => $settledPrincipal,
                    'interest_charged' => $interestCharged,
                    'interest_waived' => $interestWaived,
                    'excess_to_sukarela' => bccomp($excess, '0', self::SCALE) > 0 ? $excess : '0.00',
                    'schedules_closed' => $unpaid->count(),
                ])
                ->log("Pelunasan dipercepat pinjaman {$loan->loan_number}");

            return $installment;
        });
    }

    /**
     * Reversal pembayaran angsuran. Membalik jadwal ke Belum Bayar; bila pinjaman
     * sudah Lunas, kembalikan ke Cair dan batalkan refund SWP/Tab terkait (D8/M2).
     */
    public function reverse(Installment $installment, string $reason, ?int $causerId = null): Installment
    {
        $causerId ??= auth()->id();

        return DB::transaction(function () use ($installment, $reason, $causerId): Installment {
            $reversal = ($this->reverse)($installment, $reason, $causerId);

            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($installment->loan_id);

            if ($installment->is_settlement) {
                $normallyPaidScheduleIds = Installment::query()
                    ->where('loan_id', $loan->id)
                    ->where('is_settlement', false)
                    ->whereNotNull('schedule_id')
                    ->groupBy('schedule_id')
                    ->havingRaw('SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END) > 0')
                    ->pluck('schedule_id');

                InstallmentSchedule::query()
                    ->where('loan_id', $loan->id)
                    ->where('status', InstallmentScheduleStatus::Terbayar)
                    ->whereNotIn('id', $normallyPaidScheduleIds)
                    ->update(['status' => InstallmentScheduleStatus::BelumBayar]);
            } elseif ($installment->schedule_id) {
                InstallmentSchedule::query()
                    ->whereKey($installment->schedule_id)
                    ->update(['status' => InstallmentScheduleStatus::BelumBayar]);
            }

            // Tarik kembali kredit Sukarela dari kelebihan bayar angsuran ini.
            $this->reverseOverpaymentCredit($installment, $reason, $causerId);

            // Balik debit berpasangan (bila sumber dana = saldo simpanan) — saldo
            // sukarela anggota pulih. ADR 2026-07-22 item 1d.
            $this->reverseSavingsDebit($installment, $reason, $causerId);

            if ($loan->status === LoanStatus::Lunas) {
                $loan->update(['status' => LoanStatus::Cair]);
                $this->cleanupRefunds($loan, $reason, $causerId);
            }

            // Guard presisi Titipan Pokok (ADR 2026-08-28 item 1j). WAJIB paling
            // akhir: `overpaymentCredit()` menjawab 0.00 selama status masih Lunas,
            // jadi dijalankan sebelum pemulihan status di atas ia akan buta.
            $this->assertOverpaymentCreditNotNegative($loan, $causerId);

            return $reversal;
        });
    }

    /**
     * Tolak pembatalan yang membuat saldo Titipan Pokok minus (item 1j).
     *
     * Membatalkan setoran yang MEMBUAT titipan sesudah titipannya terpakai akan
     * menarik kembali uang yang sudah dipotongkan dari angsuran lain — saldonya
     * jadi negatif, dan koperasi menanggung selisihnya. Justru untuk inilah
     * `Loan::overpaymentCredit()` sengaja tidak di-floor ke 0.
     *
     * Guard hanya menggigit bila titipan memang pernah ada DAN sudah terpakai;
     * pinjaman yang tak pernah bertitipan tidak terkekang sama sekali — itu yang
     * membedakannya dari aturan LIFO menyeluruh yang ditolak di Alternatives.
     *
     * Pesannya menyebut angsuran penghalang yang harus dibatalkan lebih dulu,
     * karena "ditolak" tanpa jalan keluar hanya memindahkan kebuntuan ke petugas.
     */
    private function assertOverpaymentCreditNotNegative(Loan $loan, ?int $causerId): void
    {
        $credit = $loan->overpaymentCredit();

        if (bccomp($credit, '0', self::SCALE) >= 0) {
            return;
        }

        $blocker = Installment::query()
            ->where('loan_id', $loan->id)
            ->where('is_reversal', false)
            ->where('is_settlement', false)
            ->where('credit_applied', '>', 0)
            ->whereDoesntHave('reversal')
            ->orderByDesc('installment_seq')
            ->first();

        activity()
            ->performedOn($loan)
            ->causedBy($causerId)
            ->event('pembatalan_ditolak')
            ->withProperties([
                'loan_id' => $loan->id,
                'credit_after' => $credit,
                'blocking_installment' => $blocker?->installment_number,
            ])
            ->log('Pembatalan angsuran ditolak — Titipan Pokok sudah terpakai');

        throw CannotReverseTransaction::overpaymentCreditSpent($blocker?->installment_number);
    }

    /**
     * Kredit kelebihan bayar ke Simpanan Sukarela anggota. Ditautkan ke angsuran
     * via `reference_number` (= installment_number) agar bisa ditarik kembali saat
     * angsuran di-reverse. Aktivitas tercatat otomatis (SavingsDeposit LogsActivity)
     * + log eksplisit di bawah agar jejaknya jelas.
     */
    private function creditOverpaymentToSukarela(Installment $installment, Loan $loan, string $excess, ?int $causerId): void
    {
        $deposit = SavingsDeposit::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => 'sukarela',
            'amount' => $excess,
            'deposit_date' => $installment->payment_date?->toDateString() ?? now()->toDateString(),
            'deposit_method' => $installment->payment_method === 'potong_gaji' ? 'potong_gaji' : 'setor_sendiri',
            'deposited_by' => 'bendahara',
            'reference_number' => $installment->installment_number,
            'notes' => "Pengalihan kelebihan dana dari angsuran {$installment->installment_number}",
            'recorded_by' => $causerId,
        ]);

        activity()
            ->performedOn($deposit)
            ->causedBy($causerId)
            ->event('kelebihan_bayar')
            ->withProperties([
                'installment_number' => $installment->installment_number,
                'loan_id' => $loan->id,
                'amount' => $excess,
            ])
            ->log("Pengalihan kelebihan dana angsuran {$installment->installment_number} ke Simpanan Sukarela");
    }

    /**
     * Resolusi User pelaku untuk otorisasi debit simpanan. Debit sukarela =
     * uang withdrawable anggota → wajib ada pelaku terautentikasi.
     */
    private function actingUser(?int $causerId): User
    {
        $user = $causerId !== null ? User::find($causerId) : auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Aksi debit simpanan memerlukan pengguna terautentikasi.');
        }

        return $user;
    }

    /**
     * Buat SavingsWithdrawal berpasangan (status Cair) sebagai debit sumber-dana
     * angsuran (ADR 2026-07-22). Atribusi Pengurus (`approved_by`/`approved_at`)
     * mengganti mata-kedua; `installment_id` menautkan pasangan tanpa mencemari
     * query refund pelunasan (`related_loan_id`). Saldo turun langsung — `withdrawalNet`
     * hanya menghitung baris Cair.
     */
    private function debitSavingsForInstallment(Installment $installment, Loan $loan, string $amount, ?int $causerId): void
    {
        $withdrawal = SavingsWithdrawal::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => self::DEBIT_SAVINGS_TYPE,
            'amount' => $amount,
            'withdrawal_date' => now()->toDateString(),
            'status' => WithdrawalStatus::Cair,
            'approved_by' => $causerId,
            'approved_at' => now(),
            'disbursed_at' => now(),
            'installment_id' => $installment->id,
            'disbursement_method' => 'internal',
            'recorded_by' => $causerId,
            'notes' => "Debit angsuran {$installment->installment_number}",
        ]);

        activity()
            ->performedOn($withdrawal)
            ->causedBy($causerId)
            ->event('debit_simpanan_angsuran')
            ->withProperties([
                'member_id' => $loan->member_id,
                'savings_type' => self::DEBIT_SAVINGS_TYPE,
                'amount' => $amount,
                'installment_number' => $installment->installment_number,
                'loan_id' => $loan->id,
                'approved_by' => $causerId,
            ])
            ->log("Debit Simpanan Sukarela untuk angsuran {$installment->installment_number}");
    }

    /**
     * Tarik kembali kredit Sukarela saat angsuran di-reverse: balikkan deposit
     * sukarela yang tertaut (non-reversal, belum dibalik) via mekanisme reversal
     * generik agar saldo ter-net ke semula.
     */
    private function reverseOverpaymentCredit(Installment $installment, string $reason, ?int $causerId): void
    {
        $reversedIds = SavingsDeposit::query()
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id');

        SavingsDeposit::query()
            ->where('reference_number', $installment->installment_number)
            ->where('savings_type', 'sukarela')
            ->where('is_reversal', false)
            ->whereNotIn('id', $reversedIds)
            ->get()
            ->each(fn (SavingsDeposit $deposit) => ($this->reverse)($deposit, $reason, $causerId));
    }

    /**
     * Balik debit simpanan berpasangan saat angsuran di-reverse (ADR 2026-07-22 item 1d).
     * Cari SavingsWithdrawal non-reversal `Cair` ber-`installment_id` yang BELUM dibalik
     * (pola `whereNotIn(reversed_ids)`), lalu reverse dengan `allowInactiveMember: true` —
     * membalik debit MENGEMBALIKAN saldo ke anggota, harus selalu boleh walau anggota
     * sudah Keluar/Meninggal (kalau tidak, angsuran-dari-simpanan jadi permanen tak
     * bisa dibatalkan begitu anggota keluar — melanggar Goal).
     */
    private function reverseSavingsDebit(Installment $installment, string $reason, ?int $causerId): void
    {
        $reversedIds = SavingsWithdrawal::query()
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id');

        SavingsWithdrawal::query()
            ->where('installment_id', $installment->id)
            ->where('savings_type', self::DEBIT_SAVINGS_TYPE)
            ->where('is_reversal', false)
            ->where('status', WithdrawalStatus::Cair)
            ->whereNotIn('id', $reversedIds)
            ->get()
            ->each(fn (SavingsWithdrawal $debit) => ($this->reverse)($debit, $reason, $causerId, allowInactiveMember: true, allowPairedInstallmentDebit: true));
    }

    private function createRefunds(Loan $loan, ?int $causerId): void
    {
        // Metode pengembalian diwarisi dari pinjaman (ditetapkan saat akad) — satu
        // sumber kebenaran, terekam sejak awal. Fallback 'tunai' untuk pinjaman
        // lama yang belum punya disbursement_method.
        $method = $loan->disbursement_method ?? 'tunai';

        $swp = (string) $loan->swp_amount;
        if (bccomp($swp, '0', self::SCALE) > 0 && ! $this->hasActiveRefund($loan, 'swp')) {
            $this->makeRefund($loan, 'swp', $swp, $method, $causerId);
        }

        $tab = $this->loanTimeDepositAccrued($loan);
        if (bccomp($tab, '0', self::SCALE) > 0 && ! $this->hasActiveRefund($loan, 'tabungan_berjangka')) {
            $this->makeRefund($loan, 'tabungan_berjangka', $tab, $method, $causerId);
        }
    }

    /**
     * Refund auto sebagai DRAFT (D1) — saldo baru berkurang saat pengurus cair-kan
     * lewat WithdrawalWorkflow. Metode pencairan dititip di draft untuk dipakai saat
     * disburse.
     */
    private function makeRefund(Loan $loan, string $type, string $amount, string $method, ?int $causerId): void
    {
        SavingsWithdrawal::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => $type,
            'amount' => $amount,
            'withdrawal_date' => now()->toDateString(),
            'status' => 'draft',
            'related_loan_id' => $loan->id,
            'disbursement_method' => $method,
            'recorded_by' => $causerId,
            'notes' => "Pengembalian saat pelunasan pinjaman {$loan->loan_number}",
        ]);
    }

    /**
     * Idempotensi (D5): ada refund aktif (draft/acc/cair, non-reversal) bertipe ini
     * untuk pinjaman ini? Refund yang sudah `ditolak` tak menghalangi pembuatan baru
     * (mis. bayar → lunas → reverse → bayar lagi).
     */
    private function hasActiveRefund(Loan $loan, string $type): bool
    {
        return SavingsWithdrawal::query()
            ->where('related_loan_id', $loan->id)
            ->where('savings_type', $type)
            ->where('is_reversal', false)
            ->whereIn('status', [WithdrawalStatus::Draft, WithdrawalStatus::Acc, WithdrawalStatus::Cair])
            ->exists();
    }

    /**
     * Bersihkan refund yatim saat pelunasan dibatalkan (D4): draft/acc → reject
     * (terminal ditolak); cair → reverse (reversal-clone generik). Tak ada
     * hard-delete dokumen bernomor.
     */
    private function cleanupRefunds(Loan $loan, string $reason, ?int $causerId): void
    {
        /** @var Collection<int, SavingsWithdrawal> $refunds */
        $refunds = SavingsWithdrawal::query()
            ->where('related_loan_id', $loan->id)
            ->where('is_reversal', false)
            ->whereIn('savings_type', ['swp', 'tabungan_berjangka'])
            ->whereIn('status', [WithdrawalStatus::Draft, WithdrawalStatus::Acc, WithdrawalStatus::Cair])
            ->get();

        foreach ($refunds as $refund) {
            if ($refund->status === WithdrawalStatus::Cair) {
                ($this->reverse)($refund, $reason, $causerId);
            } else {
                $this->workflow->reject($refund, $causerId);
            }
        }
    }

    private function hasUnpaidSchedules(Loan $loan): bool
    {
        return InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->exists();
    }

    /**
     * Tabungan Berjangka terakumulasi pinjaman ini = `monthly_time_deposit` ×
     * jumlah angsuran terbayar (net reversal), via scope count-based. Satu rumus
     * dengan saldo (SavingsBalanceService) agar refund yang dibatalkan match.
     */
    private function loanTimeDepositAccrued(Loan $loan): string
    {
        $net = Installment::query()
            ->where('installments.loan_id', $loan->id)
            ->signedTimeDeposit()
            ->value('net');

        return bcadd((string) ($net ?? '0'), '0', self::SCALE);
    }

    private function money(string|int|float $value): string
    {
        return bcadd((string) $value, '0', self::SCALE);
    }
}
