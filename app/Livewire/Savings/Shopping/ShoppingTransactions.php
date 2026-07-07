<?php

namespace App\Livewire\Savings\Shopping;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Models\ShoppingTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ShoppingTransactions extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $reversal = 'all'; // all | 1 | 0

    // Modal reversal
    public bool $showReverse = false;

    public ?string $reverseId = null;

    public string $reverseReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ShoppingTransaction::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedReversal(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'reversal');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->reversal !== 'all';
    }

    /**
     * Reversal hanya atas baris asli (bukan reversal), yang BELUM pernah
     * di-reversal, + ber-permission (D7). Baris yang sudah di-reversal tidak
     * lagi menampilkan tombol (aksinya memang akan ditolak ReverseTransaction).
     */
    public function canReverse(ShoppingTransaction $record): bool
    {
        return ! $record->is_reversal
            && ! $record->isReversed()
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function openReverse(string $id): void
    {
        $record = ShoppingTransaction::findOrFail($id);
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
        $record = ShoppingTransaction::findOrFail($this->reverseId);
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

    public function render(): View
    {
        $transactions = ShoppingTransaction::query()
            ->with('member:id,member_number,full_name')
            ->with('reversal:id,reversal_of_id')
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('transaction_number', 'like', $term)
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', $term)
                        ->orWhere('member_number', 'like', $term));
            })
            ->when($this->reversal !== 'all', fn ($q) => $q->where('is_reversal', (int) $this->reversal))
            ->latest('created_at')
            ->paginate(15);

        return view('livewire.savings.shopping.shopping-transactions', [
            'transactions' => $transactions,
        ])->layout('components.layouts.app', ['title' => 'Belanja Toko']);
    }
}
