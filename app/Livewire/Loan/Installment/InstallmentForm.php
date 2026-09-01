<?php

namespace App\Livewire\Loan\Installment;

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Exceptions\CannotProcessPayment;
use App\Filament\Resources\InstallmentResource as Resource;
use App\Livewire\Concerns\WithMemberPicker;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class InstallmentForm extends Component
{
    use WithFileUploads;
    use WithMemberPicker;

    public ?string $loan_id = null;

    public ?string $schedule_id = null;

    /** Satu nominal diterima (ADR 2026-06-26) — prefilled = tagihan, boleh dinaikkan. */
    public ?int $amount_paid = null;

    public string $payment_method = 'potong_gaji';

    public ?string $payment_date = null;

    public ?TemporaryUploadedFile $bukti = null;

    /** Toggle pelunasan dipercepat (ADR 2026-07-22) — lunasi seluruh sisa sekaligus. */
    public bool $settle_early = false;

    /** Mode alokasi kelebihan bayar (ADR 2026-08-28) — bawaan Titipan Pokok. */
    public string $mode = LoanPaymentService::MODE_TITIPAN;

    public bool $showAllocationDialog = false;

    /** Petugas sudah memilih mode untuk nominal ini. */
    public bool $modeConfirmed = false;

    public function mount(): void
    {
        $this->authorize('create', Installment::class);

        $this->payment_date = now()->toDateString();

        // Prefill dari halaman detail pinjaman (?loan=...).
        $loanId = request()->query('loan');
        if (filled($loanId)) {
            $loan = Loan::with('member')->find($loanId);
            if ($loan && $loan->status === LoanStatus::Cair && $loan->member) {
                $this->member_id = $loan->member_id;
                $this->selectedMemberLabel = static::memberLabel($loan->member);
                $this->loan_id = $loan->id;
                $this->loadSchedule();
            }
        }
    }

    protected function afterMemberSelected(): void
    {
        $this->reset('loan_id', 'schedule_id', 'amount_paid');
        $this->dispatchAmounts();
    }

    public function updatedAmountPaid(): void
    {
        // Nominal berubah → pilihan mode sebelumnya tak lagi berlaku.
        $this->modeConfirmed = false;
        $this->mode = LoanPaymentService::MODE_TITIPAN;
    }

    public function updatedLoanId(): void
    {
        $this->reset('schedule_id', 'amount_paid', 'settle_early', 'mode', 'modeConfirmed');
        $this->loadSchedule();
        $this->dispatchAmounts();
    }

    /** Prefill nominal = jumlah pelunasan saat toggle dinyalakan; balik ke tagihan saat dimatikan. */
    public function updatedSettleEarly(): void
    {
        // Debit simpanan tak berlaku untuk pelunasan dipercepat (satu angsuran =
        // satu sumber; jalur savings hanya di pay(), bukan settleEarly()).
        if ($this->settle_early && $this->fromSavings()) {
            $this->payment_method = 'potong_gaji';
        }

        if ($this->settle_early) {
            $preview = $this->settlementPreview();
            $this->amount_paid = $preview ? (int) round((float) $preview['payoff']) : $this->amount_paid;
        } else {
            $schedule = $this->selectedSchedule();
            $this->amount_paid = $schedule ? (int) round((float) $this->effectiveBill($schedule)) : null;
        }

        $this->dispatchAmounts();
    }

    /**
     * Sumber = Saldo Simpanan Sukarela (ADR 2026-07-22). Saat dipilih: nominal
     * dikunci = tagihan (tak boleh lebih), bukti consent wajib, saldo divalidasi.
     */
    public function updatedPaymentMethod(): void
    {
        if ($this->fromSavings()) {
            $schedule = $this->selectedSchedule();
            $this->amount_paid = $schedule ? (int) round((float) $this->effectiveBill($schedule)) : $this->amount_paid;
        }

        $this->dispatchAmounts();
    }

    public function fromSavings(): bool
    {
        return $this->payment_method === 'saldo_simpanan';
    }

    /**
     * Opsi metode bayar sadar-izin: `saldo_simpanan` hanya untuk pemegang
     * `pay_installment_from_savings` (Pengurus) dan hanya di jalur angsuran biasa
     * (bukan pelunasan dipercepat).
     *
     * @return array<string, string>
     */
    public function paymentMethodOptions(): array
    {
        $methods = Resource::PAYMENT_METHODS;

        if ($this->settle_early || ! (auth()->user()?->can('payFromSavings', Installment::class) ?? false)) {
            unset($methods['saldo_simpanan']);
        }

        return $methods;
    }

    /** Saldo Simpanan Sukarela anggota terpilih — untuk info & validasi ≤ saldo. */
    public function availableSukarela(): ?string
    {
        if (blank($this->member_id)) {
            return null;
        }

        $member = Member::find($this->member_id);

        return $member ? app(SavingsBalanceService::class)->balanceByType($member, 'sukarela') : null;
    }

    /**
     * Kirim nilai server (tagihan & total prefill) ke ringkasan kanan agar tidak
     * stuck Rp 0 setelah Livewire mengganti field saat pinjaman/angsuran dipilih.
     */
    private function dispatchAmounts(): void
    {
        if ($this->settle_early) {
            $preview = $this->settlementPreview();
            $bill = $preview ? (int) round((float) $preview['payoff']) : 0;
        } else {
            $schedule = $this->selectedSchedule();
            $bill = $schedule ? (int) round((float) $this->effectiveBill($schedule)) : 0;
        }

        $this->dispatch(
            'amounts-updated',
            total: (int) $this->amount_paid,
            bill: $bill,
        );
    }

    public function selectedLoan(): ?Loan
    {
        return $this->loan_id ? Loan::find($this->loan_id) : null;
    }

    /**
     * Apakah pinjaman terpilih boleh dilunasi dipercepat? Jangka panjang, Cair,
     * masih ada sisa, dan user punya permission settle_early (ADR 2026-07-22).
     * Menentukan visibilitas checkbox — enforcement sebenarnya di pay()/settle().
     */
    public function canSettleEarly(): bool
    {
        $loan = $this->selectedLoan();

        if ($loan === null || $loan->loan_type !== 'jangka_panjang' || $loan->status !== LoanStatus::Cair) {
            return false;
        }

        if (! (auth()->user()?->can('settleEarly', Installment::class) ?? false)) {
            return false;
        }

        return InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->exists();
    }

    /**
     * Preview jumlah pelunasan + refund untuk ditampilkan sebelum konfirmasi.
     * payoff = sisa pokok + 1× jasa; refund = SWP + Tab. Berjangka terakumulasi.
     *
     * @return array{settled_principal:string, interest:string, payoff:string, refund_swp:string, refund_tab:string, refund_total:string}|null
     */
    public function settlementPreview(): ?array
    {
        $loan = $this->selectedLoan();

        if ($loan === null) {
            return null;
        }

        $settledPrincipal = $loan->settledPrincipal();
        $interest = bcadd((string) $loan->monthly_interest, '0', 2);
        // Satu sumber (item 1c) — pratinjau WAJIB memakai angka yang sama dengan
        // yang ditegakkan settleEarly(), termasuk potongan Titipan Pokok. Rumus
        // lokal di sini akan menampilkan jumlah pelunasan yang salah bagi anggota
        // bertitipan: persis bentuk R2.
        $payoff = $loan->payoffAmount();

        $tab = bcadd((string) (Installment::query()
            ->where('installments.loan_id', $loan->id)
            ->signedTimeDeposit()
            ->value('net') ?? '0'), '0', 2);
        $swp = bcadd((string) $loan->swp_amount, '0', 2);

        return [
            'settled_principal' => $settledPrincipal,
            'interest' => $interest,
            'payoff' => $payoff,
            'refund_swp' => $swp,
            'refund_tab' => $tab,
            'refund_total' => bcadd($swp, $tab, 2),
        ];
    }

    /** Ambil angsuran terlama yang belum bayar (FIFO) + prefill nominal tagihan. */
    public function loadSchedule(): void
    {
        if (blank($this->loan_id)) {
            return;
        }

        $schedule = InstallmentSchedule::query()
            ->with('loan')
            ->where('loan_id', $this->loan_id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->orderBy('installment_seq')
            ->first();

        if ($schedule === null) {
            $this->schedule_id = null;

            return;
        }

        // Prefill nominal diterima = tagihan EFEKTIF bulan ini, yaitu tagihan
        // kontrak dikurangi Titipan Pokok anggota (ADR 2026-08-28 item 2a). Boleh
        // dinaikkan; kelebihannya mengendap jadi titipan untuk bulan berikutnya.
        $this->schedule_id = $schedule->id;
        $this->amount_paid = (int) round((float) $this->effectiveBill($schedule));
    }

    /** Tagihan efektif jadwal ini — satu sumber, dari model (item 1b). */
    public function effectiveBill(?InstallmentSchedule $schedule = null): string
    {
        $schedule ??= $this->selectedSchedule();

        if ($schedule === null || $schedule->loan === null) {
            return '0.00';
        }

        return $schedule->loan->effectiveBill($schedule);
    }

    /** Saldo Titipan Pokok pinjaman terpilih — juga dikirim balik sebagai versi. */
    public function creditBalance(): string
    {
        return $this->selectedSchedule()?->loan?->overpaymentCredit() ?? '0.00';
    }

    public function activeLoanOptions(): array
    {
        return Resource::activeLoanOptions($this->member_id);
    }

    /**
     * Angsuran pelunasan = jadwal ini satu-satunya yang masih "Belum Bayar" di
     * pinjamannya; membayarnya membuat pinjaman Lunas → memicu refund SWP/Tab.
     */
    public function isFinal(): bool
    {
        if ($this->schedule_id === null) {
            return false;
        }

        $schedule = InstallmentSchedule::find($this->schedule_id);

        if ($schedule === null || $schedule->status !== InstallmentScheduleStatus::BelumBayar) {
            return false;
        }

        return ! InstallmentSchedule::query()
            ->where('loan_id', $schedule->loan_id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->whereKeyNot($schedule->getKey())
            ->exists();
    }

    public function selectedSchedule(): ?InstallmentSchedule
    {
        return $this->schedule_id ? InstallmentSchedule::with('loan')->find($this->schedule_id) : null;
    }

    protected function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:members,id'],
            'loan_id' => ['required', 'exists:loans,id'],
            'schedule_id' => ['required', 'exists:installment_schedules,id'],
            'amount_paid' => ['required', 'integer', 'min:1'],
            // Whitelist sadar-izin: Petugas tak bisa submit `saldo_simpanan`.
            'payment_method' => ['required', 'in:'.implode(',', array_keys($this->paymentMethodOptions()))],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            // Consent WAJIB saat sumber = saldo simpanan (defense-in-depth; service juga menolak).
            // `nullable` WAJIB: tanpa itu aturan `file` ikut dijalankan atas nilai
            // null, sehingga pembayaran tunai tanpa unggahan — kasus paling lazim
            // di loket — selalu ditolak dengan error "bukti". Bug lama; jalur
            // settle() di bawah sudah benar, jalur ini terlewat. Semua test lama
            // kebetulan selalu mengisi bukti, jadi tak pernah ketahuan.
            'bukti' => [Rule::requiredIf($this->fromSavings()), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'member_id' => 'anggota',
            'loan_id' => 'pinjaman',
            'schedule_id' => 'angsuran',
            'amount_paid' => 'nominal dibayar',
            'payment_method' => 'metode bayar',
            'payment_date' => 'tanggal bayar',
            'bukti' => 'bukti pembayaran',
        ];
    }

    /**
     * Rincian akibat kedua mode, DALAM ANGKA (ADR 2026-08-28 item 2a).
     *
     * Dialog dua-tombol tanpa angka memang lebih murah dibuat, tapi tagihan yang
     * berubah-ubah ("bulan ini 50.000, bulan depan 1.000.000") bukan konsep
     * koperasi yang bisa diturunkan petugas dari pengetahuan mereka — itu akibat
     * keputusan desain ADR ini, jadi sistem yang wajib menjelaskannya. Rincian ini
     * juga satu-satunya penjelasan yang petugas punya di depan anggota, sekaligus
     * pengaman yang jadi dasar penerimaan R14.
     *
     * @return array{titipan:array{closed:list<int>, credit_after:string, next:list<array{seq:int, bill:string}>}, tutup_sekalian:array{closed:list<int>, credit_after:string, next:list<array{seq:int, bill:string}>}}|null
     */
    public function allocationPreview(): ?array
    {
        $schedule = $this->selectedSchedule();

        if ($schedule === null || $schedule->loan === null || blank($this->amount_paid)) {
            return null;
        }

        $service = app(LoanPaymentService::class);
        $preview = [];

        foreach ([LoanPaymentService::MODE_TITIPAN, LoanPaymentService::MODE_TUTUP_SEKALIAN] as $mode) {
            try {
                $plan = $service->allocate($schedule->loan, $schedule, (string) (int) $this->amount_paid, $mode);
            } catch (CannotProcessPayment) {
                // Nominal di bawah tagihan atau justru cukup melunasi — kedua
                // keadaan itu ditangani jalur lain, bukan dialog ini.
                return null;
            }

            $preview[$mode] = [
                'closed' => array_map(fn (array $row): int => (int) $row['schedule']->installment_seq, $plan['rows']),
                'credit_after' => $plan['credit_after'],
                'next' => $this->upcomingBills($schedule, $plan),
            ];
        }

        return $preview;
    }

    /**
     * Tagihan bulan-bulan berikutnya bila rencana ini disimpan — inilah "akibat
     * dalam angka". Berhenti setelah titipan habis; sesudah itu tagihannya kembali
     * penuh dan tak menjelaskan apa pun.
     *
     * @param  array{rows:list<array{schedule:InstallmentSchedule, ...}>, credit_after:string}  $plan
     * @return list<array{seq:int, bill:string}>
     */
    private function upcomingBills(InstallmentSchedule $schedule, array $plan): array
    {
        $loan = $schedule->loan;
        $closed = array_map(fn (array $row): int => (int) $row['schedule']->installment_seq, $plan['rows']);

        $credit = $plan['credit_after'];
        $rows = [];

        $upcoming = InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->whereNotIn('installment_seq', $closed)
            ->orderBy('installment_seq')
            ->limit(3)
            ->get();

        foreach ($upcoming as $next) {
            $bill = $loan->effectiveBillWithCredit($next, $credit);

            $rows[] = ['seq' => (int) $next->installment_seq, 'bill' => $bill];

            $credit = bcsub($credit, bcsub((string) $next->total_due, $bill, 2), 2);

            if (bccomp($credit, '0', 2) <= 0) {
                break;
            }
        }

        return $rows;
    }

    /** Dialog hanya muncul bila sisa uang cukup menutup angsuran berikutnya. */
    public function needsAllocationChoice(): bool
    {
        $preview = $this->allocationPreview();

        return $preview !== null
            && count($preview[LoanPaymentService::MODE_TUTUP_SEKALIAN]['closed']) > 1;
    }

    /** Memilih akibat = menyetujuinya; langsung disimpan, tanpa klik kedua. */
    public function chooseMode(string $mode)
    {
        if (! in_array($mode, [LoanPaymentService::MODE_TITIPAN, LoanPaymentService::MODE_TUTUP_SEKALIAN], true)) {
            return null;
        }

        $this->mode = $mode;
        $this->modeConfirmed = true;
        $this->showAllocationDialog = false;

        return $this->pay();
    }

    public function closeAllocationDialog(): void
    {
        $this->showAllocationDialog = false;
    }

    public function pay()
    {
        if ($this->settle_early) {
            return $this->settle();
        }

        $this->authorize('create', Installment::class);

        $this->validate();

        $schedule = InstallmentSchedule::find($this->schedule_id);

        if ($schedule === null) {
            $this->dispatch('toast', type: 'error', message: 'Angsuran tidak ditemukan. Muat ulang halaman.');

            return null;
        }

        // Anti-korupsi total-level (ADR 2026-06-26 D4), kini bertumpu tagihan
        // EFEKTIF (ADR 2026-08-28): lantainya turun tepat sebesar Titipan Pokok
        // anggota. Risiko yang dibuka lantai yang turun ini DITERIMA sadar (OQ-0);
        // penggantinya kuitansi, panel riwayat, dan jejak log.
        $bill = (int) round((float) $this->effectiveBill($schedule));
        if ((int) $this->amount_paid < $bill) {
            throw ValidationException::withMessages([
                'amount_paid' => 'Nominal tidak boleh kurang dari tagihan Rp '.number_format($bill, 0, ',', '.').'.',
            ]);
        }

        // Sisa uang cukup menutup angsuran berikutnya → petugas WAJIB memilih,
        // dengan akibat kedua pilihan tersaji dalam angka. Pembulatan biasa tak
        // memunculkan apa pun.
        if (! $this->modeConfirmed && ! $this->fromSavings() && $this->needsAllocationChoice()) {
            $this->showAllocationDialog = true;

            return null;
        }

        // Sumber = saldo simpanan (ADR 2026-07-22): dikunci tepat-tagihan (tak boleh
        // lebih — cegah lingkaran debit→kelebihan→sukarela) & tak boleh melebihi saldo.
        if ($this->fromSavings()) {
            if ((int) $this->amount_paid > $bill) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Pembayaran dari saldo simpanan harus tepat sebesar tagihan Rp '.number_format($bill, 0, ',', '.').'.',
                ]);
            }

            // Bayar-dari-simpanan tetap satu angsuran, tak pernah tutup-sekalian:
            // nominalnya dikunci tepat tagihan, jadi tak ada sisa untuk dialokasikan.

            $available = $this->availableSukarela() ?? '0';
            if (bccomp((string) (int) $this->amount_paid, $available, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Melebihi saldo Simpanan Sukarela (Rp '.number_format((float) $available, 0, ',', '.').').',
                ]);
            }
        }

        $input = [
            'amount_paid' => (string) (int) $this->amount_paid,
            'payment_method' => $this->payment_method,
            'payment_date' => $this->payment_date,
            'mode' => $this->fromSavings() ? LoanPaymentService::MODE_TITIPAN : $this->mode,
            // Versi yang dipakai saat rincian dihitung. Service menolak bila saldo
            // sudah bergeser di dalam lock — ditolak, bukan disimpan diam-diam
            // dengan hasil berbeda dari yang dikonfirmasi (item 1f).
            'expected_credit' => $this->creditBalance(),
        ];

        try {
            $installment = app(LoanPaymentService::class)->pay(
                $schedule,
                $input,
                auth()->id(),
                $this->bukti?->getRealPath() ? $this->bukti : null,
            );
        } catch (CannotProcessPayment $e) {
            throw ValidationException::withMessages(['amount_paid' => $e->getMessage()]);
        }

        $lunas = $installment->loan()->where('status', LoanStatus::Lunas)->exists();
        $message = $lunas
            ? 'Angsuran tercatat & pinjaman LUNAS — SWP + Tab. Berjangka dikembalikan.'
            : 'Pembayaran angsuran '.$installment->installment_number.' tercatat.';

        session()->flash('toast', ['type' => 'success', 'message' => $message]);

        return $this->redirectRoute('installments.show', $installment, navigate: true);
    }

    /**
     * Pelunasan dipercepat (ADR 2026-07-22): lunasi seluruh sisa. Authorize dua
     * lapis (di sini + guard service). Tolak bila uang < jumlah pelunasan.
     */
    public function settle()
    {
        $this->authorize('settleEarly', Installment::class);

        // Pelunasan dipercepat TIDAK mendukung sumber saldo simpanan (settleEarly
        // tak punya jalur debit berpasangan) — kunci ke metode kas.
        $settleMethods = array_diff(array_keys(Resource::PAYMENT_METHODS), ['saldo_simpanan']);

        $this->validate([
            'member_id' => ['required', 'exists:members,id'],
            'loan_id' => ['required', 'exists:loans,id'],
            'amount_paid' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:'.implode(',', $settleMethods)],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'bukti' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $loan = Loan::find($this->loan_id);

        if ($loan === null) {
            $this->dispatch('toast', type: 'error', message: 'Pinjaman tidak ditemukan. Muat ulang halaman.');

            return null;
        }

        $input = [
            'amount_paid' => (string) (int) $this->amount_paid,
            'payment_method' => $this->payment_method,
            'payment_date' => $this->payment_date,
            'mode' => $this->fromSavings() ? LoanPaymentService::MODE_TITIPAN : $this->mode,
            // Versi yang dipakai saat rincian dihitung. Service menolak bila saldo
            // sudah bergeser di dalam lock — ditolak, bukan disimpan diam-diam
            // dengan hasil berbeda dari yang dikonfirmasi (item 1f).
            'expected_credit' => $this->creditBalance(),
        ];

        try {
            $installment = app(LoanPaymentService::class)->settleEarly(
                $loan,
                $input,
                auth()->id(),
                $this->bukti?->getRealPath() ? $this->bukti : null,
            );
        } catch (CannotProcessPayment $e) {
            throw ValidationException::withMessages(['amount_paid' => $e->getMessage()]);
        }

        session()->flash('toast', [
            'type' => 'success',
            'message' => 'Pinjaman LUNAS via pelunasan dipercepat — SWP + Tab. Berjangka dikembalikan (draft).',
        ]);

        return $this->redirectRoute('installments.show', $installment, navigate: true);
    }

    public function render(): View
    {
        $schedule = $this->selectedSchedule();

        return view('livewire.loan.installment.installment-form', [
            'loanOptions' => $this->activeLoanOptions(),
            'paymentMethods' => $this->paymentMethodOptions(),
            'schedule' => $schedule,
            'isFinal' => $this->isFinal(),
            'totalPaid' => (int) $this->amount_paid,
            'canSettleEarly' => $this->canSettleEarly(),
            'settlementPreview' => $this->settle_early ? $this->settlementPreview() : null,
            'fromSavings' => $this->fromSavings(),
            'availableSukarela' => $this->fromSavings() ? $this->availableSukarela() : null,
        ])->layout('components.layouts.app', ['title' => 'Bayar Angsuran']);
    }
}
