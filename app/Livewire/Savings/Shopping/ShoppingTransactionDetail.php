<?php

namespace App\Livewire\Savings\Shopping;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\ShoppingTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class ShoppingTransactionDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $transactionId;

    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(ShoppingTransaction $transaction): void
    {
        $this->authorize('view', $transaction);
        $this->transactionId = $transaction->id;
    }

    public function canReverse(ShoppingTransaction $record): bool
    {
        return ! $record->is_reversal
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function openReverse(): void
    {
        $record = ShoppingTransaction::findOrFail($this->transactionId);
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
        $record = ShoppingTransaction::findOrFail($this->transactionId);
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
            $this->dispatch('toast', type: 'success', message: 'Reversal berhasil — saldo Wajib Belanja telah tersesuaikan.');
        } catch (CannotReverseTransaction $e) {
            throw ValidationException::withMessages(['reverseReason' => $e->getMessage()]);
        }
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'member_id' => 'Anggota',
            'amount' => 'Nominal',
            'transaction_date' => 'Tanggal Pemakaian',
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

        if ($key === 'amount') {
            return 'Rp '.number_format((float) $value, 0, ',', '.');
        }

        return $this->defaultFormatAuditFieldValue($key, $value);
    }

    public function render(): View
    {
        $transaction = ShoppingTransaction::with(['member.agency', 'recordedBy', 'reversalOf'])
            ->findOrFail($this->transactionId);

        $activities = $transaction->activities()->with('causer')->latest()->paginate(10);
        $selectedActivity = $this->auditId
            ? $transaction->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.savings.shopping.shopping-transaction-detail', [
            'transaction' => $transaction,
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Belanja Toko']);
    }
}
