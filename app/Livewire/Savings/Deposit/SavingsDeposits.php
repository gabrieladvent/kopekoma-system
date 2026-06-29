<?php

namespace App\Livewire\Savings\Deposit;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Resources\SavingsDepositResource as Resource;
use App\Models\SavingsDeposit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SavingsDeposits extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = 'all';

    #[Url]
    public string $method = 'all';

    #[Url]
    public string $reversal = 'all'; // all | 1 | 0

    // Modal reversal
    public bool $showReverse = false;

    public ?string $reverseId = null;

    public string $reverseReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SavingsDeposit::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedMethod(): void
    {
        $this->resetPage();
    }

    public function updatedReversal(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'method', 'reversal');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->type !== 'all' || $this->method !== 'all' || $this->reversal !== 'all';
    }

    /** Reversal hanya atas baris asli (bukan reversal) + ber-permission. */
    public function canReverse(SavingsDeposit $record): bool
    {
        return ! $record->is_reversal
            && ! $record->isReversed()
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function openReverse(string $id): void
    {
        $record = SavingsDeposit::findOrFail($id);
        abort_unless($this->canReverse($record), 403);

        $this->reverseId = $id;
        $this->reverseReason = '';
        $this->resetErrorBag();
        $this->showReverse = true;
    }

    public function closeReverse(): void
    {
        $this->showReverse = false;
        $this->reset('reverseId', 'reverseReason');
    }

    public function performReverse(): void
    {
        $record = SavingsDeposit::findOrFail($this->reverseId);
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

    public function render(): View
    {
        $deposits = SavingsDeposit::query()
            ->with(['member:id,member_number,full_name', 'reversal:id,reversal_of_id'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('transaction_number', 'like', $term)
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', $term)
                        ->orWhere('member_number', 'like', $term));
            })
            ->when($this->type !== 'all', fn ($q) => $q->where('savings_type', $this->type))
            ->when($this->method !== 'all', fn ($q) => $q->where('deposit_method', $this->method))
            ->when($this->reversal !== 'all', fn ($q) => $q->where('is_reversal', (int) $this->reversal))
            ->latest('created_at')
            ->paginate(10);

        return view('livewire.savings.deposit.savings-deposits', [
            'deposits' => $deposits,
            'savingsTypes' => Resource::SAVINGS_TYPES,
            'depositMethods' => Resource::DEPOSIT_METHODS,
        ])->layout('components.layouts.app', ['title' => 'Setor Simpanan']);
    }
}
