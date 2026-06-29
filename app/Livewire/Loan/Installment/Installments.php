<?php

namespace App\Livewire\Loan\Installment;

use App\Filament\Resources\InstallmentResource as Resource;
use App\Models\Installment;
use App\Services\LoanPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Installments extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

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
        $this->authorize('viewAny', Installment::class);
    }

    public function updatingSearch(): void
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
        $this->reset('search', 'method', 'reversal');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->method !== 'all' || $this->reversal !== 'all';
    }

    public function canReverse(Installment $record): bool
    {
        return ! $record->is_reversal
            && ! $record->isReversed()
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function openReverse(string $id): void
    {
        $record = Installment::findOrFail($id);
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
        $record = Installment::findOrFail($this->reverseId);
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

    public function render(): View
    {
        $installments = Installment::query()
            ->with(['loan:id,loan_number,member_id', 'loan.member:id,member_number,full_name', 'reversal:id,reversal_of_id'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('installment_number', 'like', $term)
                    ->orWhereHas('loan', fn ($l) => $l->where('loan_number', 'like', $term))
                    ->orWhereHas('loan.member', fn ($m) => $m->where('full_name', 'like', $term)
                        ->orWhere('member_number', 'like', $term));
            })
            ->when($this->method !== 'all', fn ($q) => $q->where('payment_method', $this->method))
            ->when($this->reversal !== 'all', fn ($q) => $q->where('is_reversal', (int) $this->reversal))
            ->latest('created_at')
            ->paginate(10);

        return view('livewire.loan.installment.installments', [
            'installments' => $installments,
            'paymentMethods' => Resource::PAYMENT_METHODS,
        ])->layout('components.layouts.app', ['title' => 'Angsuran']);
    }
}
