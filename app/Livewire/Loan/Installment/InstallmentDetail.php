<?php

namespace App\Livewire\Loan\Installment;

use App\Filament\Resources\InstallmentResource as Resource;
use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\Installment;
use App\Services\LoanPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class InstallmentDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $installmentId;

    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(Installment $installment): void
    {
        $this->authorize('view', $installment);
        $this->installmentId = $installment->id;
    }

    public function canReverse(Installment $record): bool
    {
        return ! $record->is_reversal
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function openReverse(): void
    {
        $record = Installment::findOrFail($this->installmentId);
        abort_unless($this->canReverse($record), 403);

        $this->reverseReason = '';
        $this->resetErrorBag();
        $this->showReverse = true;
    }

    public function closeReverse(): void
    {
        $this->showReverse = false;
        $this->reset('reverseReason');
    }

    public function performReverse(): void
    {
        $record = Installment::findOrFail($this->installmentId);
        abort_unless($this->canReverse($record), 403);

        $this->validate(
            ['reverseReason' => ['required', 'string', 'min:5', 'max:65535']],
            [
                'reverseReason.required' => 'Alasan reversal wajib diisi.',
                'reverseReason.min' => 'Alasan reversal minimal 5 karakter.',
            ],
            ['reverseReason' => 'alasan reversal'],
        );

        try {
            app(LoanPaymentService::class)->reverse($record, $this->reverseReason);

            $this->closeReverse();
            $this->dispatch('toast', type: 'success', message: 'Reversal berhasil — jadwal kembali Belum Bayar.');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['reverseReason' => $e->getMessage()]);
        }
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'loan_id' => 'Pinjaman',
            'schedule_id' => 'Jadwal',
            'installment_seq' => 'Angsuran ke',
            'payment_date' => 'Tgl Bayar',
            'due_date' => 'Jatuh Tempo',
            'principal_paid' => 'Pokok',
            'interest_paid' => 'Jasa',
            'time_deposit_saved' => 'Tab. Berjangka',
            'amount_paid' => 'Total Dibayar',
            'remaining_principal' => 'Sisa Pokok',
            'payment_method' => 'Metode Bayar',
            'notes' => 'Catatan',
            'is_reversal' => 'Reversal',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    protected function formatAuditFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'principal_paid', 'interest_paid', 'time_deposit_saved',
            'amount_paid', 'remaining_principal' => 'Rp '.number_format((float) $value, 0, ',', '.'),
            'payment_method' => Resource::PAYMENT_METHODS[$value] ?? (string) $value,
            default => $this->defaultFormatAuditFieldValue($key, $value),
        };
    }

    public function render(): View
    {
        $installment = Installment::with(['loan.member.agency', 'recordedBy', 'reversalOf'])
            ->findOrFail($this->installmentId);

        $activities = $installment->activities()->with('causer')->latest()->paginate(8);
        $selectedActivity = $this->auditId
            ? $installment->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.loan.installment.installment-detail', [
            'installment' => $installment,
            'paymentMethodLabel' => Resource::PAYMENT_METHODS[$installment->payment_method] ?? $installment->payment_method,
            'bukti' => $installment->getFirstMedia('bukti'),
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Angsuran']);
    }
}
