<?php

namespace App\Livewire\Savings\Withdrawal;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotProcessWithdrawal;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Resources\SavingsWithdrawalResource as Resource;
use App\Models\SavingsWithdrawal;
use App\Services\WithdrawalWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SavingsWithdrawals extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = 'all';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $reversal = 'all'; // all | 1 | 0

    public bool $showConfirm = false;

    public ?string $confirmId = null;

    public string $confirmAction = '';

    // Modal reversal
    public bool $showReverse = false;

    public ?string $reverseId = null;

    public string $reverseReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', SavingsWithdrawal::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedReversal(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'status', 'reversal');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->type !== 'all' || $this->status !== 'all' || $this->reversal !== 'all';
    }

    // ── Gating per-record (mirror Resource: visible() + Policy) ──

    public function canApprove(SavingsWithdrawal $record): bool
    {
        return $record->status === 'draft' && (auth()->user()?->can('approve', $record) ?? false);
    }

    public function canDisburse(SavingsWithdrawal $record): bool
    {
        return $record->status === 'acc' && (auth()->user()?->can('disburse', $record) ?? false);
    }

    public function canReject(SavingsWithdrawal $record): bool
    {
        return in_array($record->status, ['draft', 'acc'], true) && (auth()->user()?->can('approve', $record) ?? false);
    }

    public function canReverse(SavingsWithdrawal $record): bool
    {
        return $record->status === 'cair'
            && ! $record->is_reversal
            && ! $record->isReversed()
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function hasAnyAction(SavingsWithdrawal $record): bool
    {
        return $this->canApprove($record) || $this->canDisburse($record)
            || $this->canReject($record) || $this->canReverse($record);
    }

    // ── Konfirmasi transisi state machine ──

    /** Metadata modal konfirmasi per aksi (judul, deskripsi, label, nada). */
    public function confirmMeta(): array
    {
        return [
            'approve' => ['title' => 'Setujui Pencairan', 'desc' => 'Menyetujui pengajuan ini. Dana belum keluar sampai dicairkan.', 'cta' => 'Setujui (ACC)', 'variant' => 'primary', 'icon' => 'check'],
            'disburse' => ['title' => 'Cairkan Dana', 'desc' => 'Menandai pencairan sebagai cair. Saldo anggota akan berkurang dan tercatat di log audit.', 'cta' => 'Cairkan', 'variant' => 'primary', 'icon' => 'banknotes'],
            'reject' => ['title' => 'Tolak Pencairan', 'desc' => 'Menolak pengajuan. Status ditolak bersifat final dan tidak dapat dibuka kembali.', 'cta' => 'Tolak', 'variant' => 'danger', 'icon' => 'x'],
        ][$this->confirmAction] ?? ['title' => '', 'desc' => '', 'cta' => '', 'variant' => 'primary', 'icon' => 'check'];
    }

    public function openConfirm(string $action, string $id): void
    {
        $record = SavingsWithdrawal::findOrFail($id);

        $allowed = match ($action) {
            'approve' => $this->canApprove($record),
            'disburse' => $this->canDisburse($record),
            'reject' => $this->canReject($record),
            default => false,
        };

        abort_unless($allowed, 403);

        $this->confirmAction = $action;
        $this->confirmId = $id;
        $this->showConfirm = true;
    }

    public function closeConfirm(): void
    {
        $this->showConfirm = false;
        $this->reset('confirmId', 'confirmAction');
    }

    public function performConfirm(): void
    {
        $record = SavingsWithdrawal::findOrFail($this->confirmId);
        $action = $this->confirmAction;

        $allowed = match ($action) {
            'approve' => $this->canApprove($record),
            'disburse' => $this->canDisburse($record),
            'reject' => $this->canReject($record),
            default => false,
        };

        abort_unless($allowed, 403);

        $workflow = app(WithdrawalWorkflow::class);

        // Pasangan refund pelunasan (swp+tab, related_loan_id sama) ditampilkan &
        // diproses sebagai SATU entri (D2): transisi diterapkan ke kedua baris
        // secara atomik. Pencairan biasa → refundPair() berisi dirinya saja.
        $pair = Resource::refundPair($record);

        try {
            DB::transaction(function () use ($pair, $action, $workflow): void {
                foreach ($pair as $member) {
                    match ($action) {
                        'approve' => $workflow->approve($member),
                        'disburse' => $workflow->disburse($member),
                        'reject' => $workflow->reject($member),
                    };
                }
            });

            $messages = [
                'approve' => 'Pencairan disetujui (ACC). Saldo belum berkurang — dana keluar saat dicairkan.',
                'disburse' => 'Dana dicairkan. Saldo anggota telah berkurang.',
                'reject' => 'Pengajuan pencairan ditolak.',
            ];

            $this->closeConfirm();
            $this->dispatch('toast', type: 'success', message: $messages[$action]);
        } catch (CannotProcessWithdrawal $e) {
            $this->closeConfirm();
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    // ── Reversal ──

    public function openReverse(string $id): void
    {
        $record = SavingsWithdrawal::findOrFail($id);
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
        $record = SavingsWithdrawal::findOrFail($this->reverseId);
        abort_unless($this->canReverse($record), 403);

        $this->validate(
            ['reverseReason' => ['required', 'string', 'min:5', 'max:65535']],
            [
                'reverseReason.required' => 'Alasan reversal wajib diisi.',
                'reverseReason.min' => 'Alasan reversal minimal 5 karakter.',
            ],
            ['reverseReason' => 'alasan reversal'],
        );

        // Refund pelunasan yang sudah cair di-reverse berpasangan (D2/D4) agar
        // saldo SWP & Tab kembali bersama; pencairan biasa → dirinya saja.
        $pair = Resource::refundPair($record);

        try {
            DB::transaction(function () use ($pair): void {
                $reverse = app(ReverseTransaction::class);
                foreach ($pair as $member) {
                    $reverse($member, $this->reverseReason);
                }
            });

            $this->closeReverse();
            $this->dispatch('toast', type: 'success', message: 'Reversal berhasil — saldo simpanan telah tersesuaikan.');
        } catch (CannotReverseTransaction $e) {
            throw ValidationException::withMessages(['reverseReason' => $e->getMessage()]);
        }
    }

    public function render(): View
    {
        $withdrawals = SavingsWithdrawal::query()
            ->with(['member:id,member_number,full_name', 'reversal:id,reversal_of_id'])
            // D2: sembunyikan baris tab sekunder yang punya saudara swp se-pinjaman;
            // swp jadi wakil entri "Pengembalian Pelunasan" (total = swp+tab).
            ->tap(fn ($q) => Resource::hideSecondaryPairRows($q))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('withdrawal_number', 'like', $term)
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', $term)
                        ->orWhere('member_number', 'like', $term));
            })
            ->when($this->type !== 'all', fn ($q) => $q->where('savings_type', $this->type))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->reversal !== 'all', fn ($q) => $q->where('is_reversal', (int) $this->reversal))
            ->latest('created_at')
            ->paginate(10);

        return view('livewire.savings.withdrawal.savings-withdrawals', [
            'withdrawals' => $withdrawals,
            'withdrawalTypes' => Resource::WITHDRAWAL_TYPES,
            'statuses' => Resource::STATUSES,
            'disbursementMethods' => Resource::DISBURSEMENT_METHODS,
        ])->layout('components.layouts.app', ['title' => 'Pencairan Simpanan']);
    }
}
