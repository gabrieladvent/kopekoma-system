<?php

namespace App\Livewire\Loan\Installment;

use App\Livewire\Savings\Deposit\BatchSalaryDeduction;
use App\Models\Agency;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Services\BatchInstallmentPaymentService;
use App\Services\LoanPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Batch potong gaji ANGSURAN per OPD — versi Livewire (analog
 *
 * {@see BatchSalaryDeduction} untuk simpanan).
 * Tiap baris = pinjaman aktif anggota dengan jadwal terlama belum bayar (FIFO);
 * eksekusi didelegasikan ke {@see BatchInstallmentPaymentService} (reuse
 * {@see LoanPaymentService}). Bukti opsional per-baris diunggah
 * lewat {@see WithFileUploads} dan dilampirkan saat proses.
 */
class BatchInstallmentPayment extends Component
{
    use WithFileUploads;

    public const PERMISSION = 'access_batch_salary_deduction';

    public ?string $agency_id = null;

    public ?string $period_month = null;

    public ?string $payment_date = null;

    /**
     * Baris per anggota: member_id, member_label, include, lines[] (pinjaman aktif
     * dengan schedule_id/loan_id/total_due/labels/include/amount).
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    public array $bukti = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(self::PERMISSION) ?? false, 403);

        $this->period_month = now()->format('Y-m');

        $this->payment_date = now()->toDateString();
    }

    public function updatedAgencyId(): void
    {
        $this->rebuildRows();
    }

    public function updatedBukti(): void
    {
        $this->dispatch('rows-updated');
    }

    public function rebuildRows(): void
    {
        $this->bukti = [];

        $this->rows = $this->buildRows($this->agency_id);

        $this->dispatch('rows-updated');
    }

    public function setAllIncluded(bool $value): void
    {
        $this->rows = collect($this->rows)
            ->map(function (array $row) use ($value): array {
                $row['include'] = $value;

                return $row;
            })
            ->all();

        $this->dispatch('rows-updated');
    }

    /** @return Collection<int, Agency> */
    public function agencies(): Collection
    {
        return Agency::query()->orderBy('agency_name')->get(['id', 'agency_code', 'agency_name']);
    }

    /**
     * Baris per anggota OPD terpilih yang punya pinjaman aktif. Tiap anggota
     * membawa daftar pinjaman aktifnya (`lines`); tiap pinjaman = jadwal terlama
     * belum bayar (FIFO), prefill nominal = tagihan bulan itu.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildRows(?string $agencyId): array
    {
        if (blank($agencyId)) {
            return [];
        }

        return Loan::query()
            ->where('status', 'Cair')
            ->whereHas('member', fn ($q) => $q->where('agency_id', $agencyId)->where('status', 'Aktif'))
            ->with('member')
            ->get()
            ->groupBy('member_id')
            ->map(function (Collection $loans): array {
                $member = $loans->first()->member;

                $lines = $loans
                    ->map(fn (Loan $loan): ?array => $this->buildLoanLine($loan))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'member_id' => $member->id,
                    'member_label' => "{$member->member_number} — {$member->full_name}",
                    'include' => true,
                    'lines' => $lines,
                ];
            })
            ->filter(fn (array $row): bool => $row['lines'] !== [])
            ->sortBy('member_label')
            ->values()
            ->all();
    }

    /**
     * Satu baris pinjaman: jadwal terlama belum bayar (FIFO). Null bila tak ada
     * jadwal belum bayar (anomali — pinjaman Cair semestinya punya sisa jadwal).
     *
     * @return array<string, mixed>|null
     */
    protected function buildLoanLine(Loan $loan): ?array
    {
        $schedule = InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', 'Belum Bayar')
            ->orderBy('installment_seq')
            ->first();

        if ($schedule === null) {
            return null;
        }

        $bill = (string) (int) round((float) $schedule->total_due);

        return [
            'loan_id' => $loan->id,
            'schedule_id' => $schedule->id,
            'loan_number' => $loan->loan_number,
            'total_due' => $bill,
            'seq' => $schedule->installment_seq,
            'due_date' => $schedule->due_date?->translatedFormat('d M Y'),
            'principal_amount' => (string) (int) round((float) $loan->principal_amount),
            'remaining_principal' => (string) (int) round((float) $loan->remainingPrincipal()),
            'include' => true,
            'amount' => $bill,
        ];
    }

    /**
     * Total nominal seluruh baris yang diikutkan (anggota Ikut & pinjaman Ikut).
     * Konsisten dengan filter di {@see process()}.
     */
    public function grandTotal(): int
    {
        return (int) collect($this->rows)
            ->filter(fn (array $r): bool => (bool) ($r['include'] ?? false))
            ->flatMap(fn (array $r): array => $r['lines'] ?? [])
            ->filter(fn (array $line): bool => (bool) ($line['include'] ?? false))
            ->sum(fn (array $line): int => (int) round((float) ($line['amount'] ?? 0)));
    }

    public function process()
    {
        abort_unless(auth()->user()?->can(self::PERMISSION) ?? false, 403);

        $this->validate(
            [
                'agency_id' => ['required', 'exists:agencies,id'],
                'period_month' => ['required', 'date_format:Y-m'],
                'payment_date' => ['required', 'date', 'before_or_equal:today'],
                'bukti.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            ],
            [],
            ['agency_id' => 'OPD', 'period_month' => 'periode', 'payment_date' => 'tanggal bayar'],
        );

        $agency = Agency::findOrFail($this->agency_id);

        $rows = collect($this->rows)
            ->filter(fn (array $r): bool => (bool) ($r['include'] ?? false))
            ->flatMap(fn (array $r): array => collect($r['lines'] ?? [])
                ->filter(fn (array $line): bool => (bool) ($line['include'] ?? false))
                ->map(fn (array $line): array => [
                    'schedule_id' => (string) $line['schedule_id'],
                    'amount_paid' => (string) (int) round((float) ($line['amount'] ?? 0)),
                    'payment_date' => $this->payment_date,
                    'bukti' => $this->bukti[$line['schedule_id']] ?? null,
                ])
                ->values()
                ->all())
            ->values()
            ->all();

        if ($rows === []) {
            $this->dispatch('toast', type: 'warning', message: 'Aktifkan minimal satu anggota dan satu pinjaman untuk dibayar.');

            return null;
        }

        try {
            $result = app(BatchInstallmentPaymentService::class)->run($agency, $this->period_month, $rows, auth()->id());
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        }

        session()->flash('toast', [
            'type' => 'success',
            'message' => "Batch selesai — {$result['created']} angsuran dicatat, {$result['skipped']} dilewati (sudah terbayar / pinjaman lunas).",
        ]);

        return $this->redirectRoute('installments.index', navigate: true);
    }

    public function render(): View
    {
        $includedMembers = collect($this->rows)->where('include', true)->count();

        return view('livewire.loan.installment.batch-installment-payment', [
            'agencies' => $this->agencies(),
            'includedMembers' => $includedMembers,
            'memberCount' => count($this->rows),
        ])->layout('components.layouts.app', ['title' => 'Batch Potong Gaji Angsuran']);
    }
}
