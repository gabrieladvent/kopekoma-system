<?php

namespace App\Livewire\Loan;

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

        if (Resource::hasPayments($record)) {
            $this->closeCorrect();
            $this->dispatch('toast', type: 'error', message: 'Pinjaman sudah punya angsuran terbayar — koreksi tidak dapat dilakukan.');

            return null;
        }

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
                ->log('Koreksi salah-input pinjaman: '.$this->correctReason);

            InstallmentSchedule::where('loan_id', $record->id)->delete();
            $record->delete();
        });

        session()->flash('toast', ['type' => 'success', 'message' => 'Pinjaman dikoreksi (record & jadwal dihapus, tercatat di audit).']);

        return $this->redirectRoute('loans.index', navigate: true);
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
            'first_due_date' => 'Jatuh Tempo Pertama',
            'status' => 'Status',
            'notes' => 'Catatan',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    protected function formatAuditFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'principal_amount', 'admin_fee', 'swp_amount', 'disbursed_amount',
            'monthly_principal', 'monthly_interest', 'monthly_time_deposit' => 'Rp '.number_format((float) $value, 0, ',', '.'),
            'loan_type' => Resource::LOAN_TYPES[$value] ?? (string) $value,
            'term_months' => $value.' bulan',
            default => $this->defaultFormatAuditFieldValue($key, $value),
        };
    }

    public function render(): View
    {
        $loan = Loan::with(['member.agency', 'recordedBy'])->findOrFail($this->loanId);

        // Statistik progres dari hitungan penuh (bukan halaman saat ini).
        $total = (int) $loan->schedules()->count();
        $paid = (int) $loan->schedules()->where('status', 'Terbayar')->count();
        $overdue = app(LoanArrearsService::class)->overdueCount($loan);
        $percent = $total > 0 ? (int) round($paid / $total * 100) : 0;

        // Tabel angsuran — default hanya yang terbayar, paginate 10 (page key sendiri).
        $schedules = $loan->schedules()
            ->with(['installments' => fn ($q) => $q->where('is_reversal', false)->latest()])
            ->when(! $this->showAllSchedules, fn ($q) => $q->where('status', 'Terbayar'))
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
        ])->layout('components.layouts.app', ['title' => 'Detail Pinjaman']);
    }
}
