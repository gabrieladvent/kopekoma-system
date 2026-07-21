<?php

namespace App\Livewire\Savings\Deposit;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Resources\SavingsDepositResource as Resource;
use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\SavingsDeposit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class SavingsDepositDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $depositId;

    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(SavingsDeposit $deposit): void
    {
        $this->authorize('view', $deposit);
        $this->depositId = $deposit->id;
    }

    public function canReverse(SavingsDeposit $record): bool
    {
        return ! $record->is_reversal
            && ! $record->isReversed()
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function openReverse(): void
    {
        $record = SavingsDeposit::findOrFail($this->depositId);
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
        $record = SavingsDeposit::findOrFail($this->depositId);
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
            app(ReverseTransaction::class)($record, $this->reverseReason);

            $this->closeReverse();
            $this->dispatch('toast', type: 'success', message: 'Reversal berhasil — saldo simpanan telah tersesuaikan.');
        } catch (CannotReverseTransaction $e) {
            throw ValidationException::withMessages(['reverseReason' => $e->getMessage()]);
        }
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'member_id' => 'Anggota',
            'savings_type' => 'Jenis Simpanan',
            'amount' => 'Nominal',
            'deposit_date' => 'Tanggal Setor',
            'period_month' => 'Periode',
            'deposit_method' => 'Metode Setor',
            'deposited_by' => 'Disetor Oleh',
            'reference_number' => 'No. Referensi',
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
            'amount' => 'Rp '.number_format((float) $value, 0, ',', '.'),
            'savings_type' => Resource::SAVINGS_TYPES[$value] ?? (string) $value,
            'deposit_method' => Resource::DEPOSIT_METHODS[$value] ?? (string) $value,
            'deposited_by' => Resource::DEPOSITED_BY[$value] ?? (string) $value,
            default => $this->defaultFormatAuditFieldValue($key, $value),
        };
    }

    public function render(): View
    {
        $deposit = SavingsDeposit::with(['member.agency', 'recordedBy', 'reversalOf'])
            ->findOrFail($this->depositId);

        $activities = $deposit->activities()->with('causer')->latest()->paginate(10);
        $selectedActivity = $this->auditId
            ? $deposit->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.savings.deposit.savings-deposit-detail', [
            'deposit' => $deposit,
            'savingsTypeLabel' => Resource::SAVINGS_TYPES[$deposit->savings_type] ?? $deposit->savings_type,
            'savingsTypeColor' => Resource::typeColor($deposit->savings_type),
            'depositMethodLabel' => Resource::DEPOSIT_METHODS[$deposit->deposit_method] ?? $deposit->deposit_method,
            'depositedByLabel' => Resource::DEPOSITED_BY[$deposit->deposited_by] ?? $deposit->deposited_by,
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Setoran']);
    }
}
