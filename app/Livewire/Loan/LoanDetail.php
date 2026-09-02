<?php

namespace App\Livewire\Loan;

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Exceptions\CannotCancelLoan;
use App\Filament\Resources\LoanResource as Resource;
use App\Filament\Resources\RelationManagers\SchedulesRelationManager;
use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Services\LoanArrearsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class LoanDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $loanId;

    /** Default tampil hanya angsuran terbayar; toggle untuk lihat rancangan penuh. */
    public bool $showAllSchedules = false;

    public bool $showCorrect = false;

    public string $correctReason = '';

    public function mount(Loan $loan): void
    {
        $this->authorize('view', $loan);
        $this->loanId = $loan->id;
    }

    public function updatedShowAllSchedules(): void
    {
        $this->resetPage('schedulePage');
    }

    public function canCorrect(Loan $record): bool
    {
        return Resource::canCorrect($record);
    }

    public function openCorrect(): void
    {
        $record = Loan::findOrFail($this->loanId);
        abort_unless($this->canCorrect($record), 403);

        $this->correctReason = '';
        $this->resetErrorBag();
        $this->showCorrect = true;
    }

    public function closeCorrect(): void
    {
        $this->showCorrect = false;
        $this->reset('correctReason');
    }

    public function performCorrect()
    {
        $record = Loan::findOrFail($this->loanId);
        abort_unless($this->canCorrect($record), 403);

        $this->validate(
            ['correctReason' => ['required', 'string', 'min:5', 'max:65535']],
            [
                'correctReason.required' => 'Alasan koreksi wajib diisi.',
                'correctReason.min' => 'Alasan koreksi minimal 5 karakter.',
            ],
            ['correctReason' => 'alasan koreksi'],
        );

        if ($record->status !== LoanStatus::Cair || Resource::hasPayments($record)) {
            $this->closeCorrect();
            $this->dispatch('toast', type: 'error', message: 'Hanya pinjaman Cair yang belum punya angsuran terbayar yang dapat dibatalkan.');

            return null;
        }

        // Record DIPERTAHANKAN sebagai histori (status → Dibatalkan); hanya jadwal
        // proyeksi yang dibuang agar tak terhitung tunggakan. Selaras dgn
        // LoanResource::performCorrection — bukan hard-delete.
        try {
            DB::transaction(function () use ($record): void {
                activity()
                    ->performedOn($record)
                    ->causedBy(auth()->id())
                    ->event('koreksi')
                    ->withProperties([
                        'loan_number' => $record->loan_number,
                        'member_id' => $record->member_id,
                        'principal_amount' => $record->principal_amount,
                    ])
                    ->log('Pembatalan salah-input pinjaman: '.$this->correctReason);

                InstallmentSchedule::where('loan_id', $record->id)->delete();
                $record->update(['status' => LoanStatus::Dibatalkan]);
            });
        } catch (CannotCancelLoan $e) {
            // Pembatalan menolak diri sendiri bila SWP pinjaman ini sudah
            // ditarik anggota — saldo simpanannya akan jadi minus. Pesannya
            // sudah menyebut jalan keluarnya, jadi cukup diteruskan apa adanya.
            $this->closeCorrect();
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        }

        $this->closeCorrect();
        $this->dispatch('toast', type: 'success', message: 'Pinjaman dibatalkan — tetap tersimpan sebagai histori, jadwal dibersihkan, tercatat di audit.');

        return null;
    }

    public function scheduleStatusLabel(InstallmentSchedule $schedule): string
    {
        return SchedulesRelationManager::statusLabel($schedule);
    }

    public function scheduleStatusColor(string $label): string
    {
        return SchedulesRelationManager::statusColor($label);
    }

    public function actualPayment(InstallmentSchedule $schedule): ?Installment
    {
        return SchedulesRelationManager::actualPayment($schedule);
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'member_id' => 'Anggota',
            'loan_type' => 'Jenis Pinjaman',
            'principal_amount' => 'Jumlah Diajukan',
            'admin_fee' => 'Biaya Admin',
            'swp_amount' => 'SWP',
            'disbursed_amount' => 'Dana Diterima',
            'term_months' => 'Jangka Waktu',
            'monthly_principal' => 'Pokok / bulan',
            'monthly_interest' => 'Jasa / bulan',
            'monthly_time_deposit' => 'Tab. Berjangka / bulan',
            'disbursement_date' => 'Tgl Pencairan',
            'disbursement_method' => 'Jenis Pencairan',
            'disbursement_bank' => 'Bank Tujuan',
            'disbursement_account_number' => 'No. Rekening Tujuan',
            'first_due_date' => 'Jatuh Tempo Pertama',
            'status' => 'Status',
            'notes' => 'Catatan',
            // Titipan Pokok (ADR 2026-08-28 item 2g / R19). Event
            // `pembatalan_ditolak` dicatat PADA PINJAMAN, bukan pada angsuran,
            // jadi peta di InstallmentDetail tak menjangkaunya — tanpa baris di
            // bawah ia tampil sebagai nama kolom mentah di satu-satunya layar
            // tempat ia muncul. Peta ini eksplisit per-layar; kolom baru tidak
            // pernah terbaca otomatis.
            'loan_id' => 'Pinjaman',
            'credit_after' => 'Titipan Pokok sesudah',
            'credit_in_settlement' => 'Titipan Pokok tertahan pelunasan',
            'blocking_installment' => 'Angsuran penghalang',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    protected function formatAuditFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'principal_amount', 'admin_fee', 'swp_amount', 'disbursed_amount',
            'monthly_principal', 'monthly_interest', 'monthly_time_deposit',
            'credit_after', 'credit_in_settlement' => 'Rp '.number_format((float) $value, 0, ',', '.'),
            'loan_type' => Resource::LOAN_TYPES[$value] ?? (string) $value,
            'disbursement_method' => Resource::DISBURSEMENT_METHODS[$value] ?? (string) $value,
            'term_months' => $value.' bulan',
            default => $this->defaultFormatAuditFieldValue($key, $value),
        };
    }

    /**
     * Riwayat Titipan Pokok (ADR 2026-08-28 item 2e) — *kapan masuk, kapan
     * dipotong dan berapa, kapan habis*. Seluruhnya dihitung dari riwayat
     * angsuran yang sudah ada; tak ada tabel, kolom, atau mesin tambahan.
     *
     * Gerak saldo per baris = `Δ = uang diterima − tagihan kontrak`, dengan tanda
     * dibalik untuk baris pembalik. Bentuk ini menangani pembatalan dengan
     * sendirinya, dan Σ Δ persis sama dengan `Loan::overpaymentCredit()`.
     *
     * Baris ber-Δ nol (pembayaran pas) dilewati — ia tak menggerakkan saldo dan
     * hanya jadi derau di tabel yang tugasnya menjelaskan pergerakan.
     *
     * **Tanpa pelacakan per-lot**, sesuai keputusan Design: sistem tidak mencatat
     * potongan ini berasal dari setoran yang mana. Titipan adalah satu kantong.
     *
     * @return list<array{date:?string, number:string, in:string, used:string, balance:string, reversal:bool, note:?string}>
     */
    private function overpaymentCreditHistory(Loan $loan): array
    {
        $monthly = $loan->monthlyTotal();

        $rows = Installment::query()
            ->where('loan_id', $loan->id)
            ->where('is_settlement', false)
            ->whereNotNull('credit_applied')
            ->orderBy('installment_number')
            ->get();

        $balance = '0.00';
        $history = [];

        foreach ($rows as $row) {
            $paid = bcadd((string) $row->amount_paid, '0', 2);

            $delta = $row->is_reversal
                ? bcsub($monthly, $paid, 2)
                : bcsub($paid, $monthly, 2);

            if (bccomp($delta, '0', 2) === 0) {
                continue;
            }

            $balance = bcadd($balance, $delta, 2);

            $history[] = [
                'date' => $row->payment_date?->format('d/m/Y'),
                'number' => $row->installment_number,
                'in' => bccomp($delta, '0', 2) > 0 ? $delta : '0.00',
                'used' => bccomp($delta, '0', 2) < 0 ? bcmul($delta, '-1', 2) : '0.00',
                'balance' => $balance,
                'reversal' => (bool) $row->is_reversal,
                'note' => null,
            ];
        }

        // Saat pinjaman ditutup, titipan yang tersisa keluar lewat DUA pintu yang
        // berbeda, dan tabel ini harus memisahkannya. Baris pelunasan sengaja
        // tidak ikut di-loop di atas (rumus saldo count-based mengecualikannya),
        // jadi tanpa pemisahan ini seluruh sisanya dilabeli "Dilimpahkan ke
        // Simpanan Sukarela" — termasuk bagian yang sebenarnya dimakan potongan
        // Pelunasan Dipercepat dan tak pernah sampai ke simpanan anggota. Salah
        // label di tabel yang tugasnya menjawab "titipan saya ke mana" adalah
        // kesalahan yang justru menutup pertanyaannya.
        if (bccomp($balance, '0', 2) > 0
            && in_array($loan->status, [LoanStatus::Lunas, LoanStatus::Dibatalkan], true)) {
            $inSettlement = $loan->settlementCreditApplied();

            if (bccomp($inSettlement, $balance, 2) > 0) {
                $inSettlement = $balance;
            }

            if (bccomp($inSettlement, '0', 2) > 0) {
                $balance = bcsub($balance, $inSettlement, 2);

                $history[] = [
                    'date' => null,
                    'number' => '—',
                    'in' => '0.00',
                    'used' => $inSettlement,
                    'balance' => $balance,
                    'reversal' => false,
                    'note' => 'Memotong jumlah Pelunasan Dipercepat',
                ];
            }

            if (bccomp($balance, '0', 2) > 0) {
                $history[] = [
                    'date' => null,
                    'number' => '—',
                    'in' => '0.00',
                    'used' => $balance,
                    'balance' => '0.00',
                    'reversal' => false,
                    'note' => 'Dilimpahkan ke Simpanan Sukarela saat pinjaman ditutup',
                ];
            }
        }

        return $history;
    }

    public function render(): View
    {
        $loan = Loan::with(['member.agency', 'recordedBy'])->findOrFail($this->loanId);

        // Statistik progres dari hitungan penuh (bukan halaman saat ini).
        $total = (int) $loan->schedules()->count();
        $paid = (int) $loan->schedules()->where('status', InstallmentScheduleStatus::Terbayar)->count();
        $overdue = app(LoanArrearsService::class)->overdueCount($loan);
        $percent = $total > 0 ? (int) round($paid / $total * 100) : 0;

        // Tabel angsuran — default hanya yang terbayar, paginate 10 (page key sendiri).
        $schedules = $loan->schedules()
            ->with(['installments' => fn ($q) => $q->where('is_reversal', false)->latest()])
            ->when(! $this->showAllSchedules, fn ($q) => $q->where('status', InstallmentScheduleStatus::Terbayar))
            ->orderBy('installment_seq')
            ->paginate(10, ['*'], 'schedulePage');

        $latestPayment = $loan->installments()
            ->where('is_reversal', false)
            ->latest()
            ->first();
        $remaining = (string) ($latestPayment?->remaining_principal ?? $loan->principal_amount);

        $activities = $loan->activities()->with('causer')->latest()->paginate(8);
        $selectedActivity = $this->auditId
            ? $loan->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.loan.loan-detail', [
            'loan' => $loan,
            'loanTypeLabel' => Resource::LOAN_TYPES[$loan->loan_type] ?? $loan->loan_type,
            'documents' => $loan->getMedia('dokumen'),
            'schedules' => $schedules,
            'progress' => [
                'total' => $total,
                'paid' => $paid,
                'overdue' => $overdue,
                'percent' => $percent,
                'remaining' => $remaining,
            ],
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
            'creditBalance' => $loan->overpaymentCredit(),
            'creditHistory' => $this->overpaymentCreditHistory($loan),
        ])->layout('components.layouts.app', ['title' => 'Detail Pinjaman']);
    }
}
