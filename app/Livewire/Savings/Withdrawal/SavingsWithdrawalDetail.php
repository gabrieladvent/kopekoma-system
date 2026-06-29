<?php

namespace App\Livewire\Savings\Withdrawal;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotProcessWithdrawal;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Resources\SavingsWithdrawalResource as Resource;
use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\SavingsWithdrawal;
use App\Services\WithdrawalWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class SavingsWithdrawalDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $withdrawalId;

    // Modal konfirmasi transisi
    public bool $showConfirm = false;

    public string $confirmAction = '';

    // Modal reversal
    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(SavingsWithdrawal $withdrawal): void
    {
        $this->authorize('view', $withdrawal);
        $this->withdrawalId = $withdrawal->id;
    }

    protected function record(): SavingsWithdrawal
    {
        return SavingsWithdrawal::findOrFail($this->withdrawalId);
    }

    // ── Gating ──

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
        return $record->status === 'cair' && ! $record->is_reversal && ! $record->isReversed() && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function confirmMeta(): array
    {
        return [
            'approve' => ['title' => 'Setujui Pencairan', 'desc' => 'Menyetujui pengajuan ini. Dana belum keluar sampai dicairkan.', 'cta' => 'Setujui (ACC)', 'variant' => 'primary', 'icon' => 'check'],
            'disburse' => ['title' => 'Cairkan Dana', 'desc' => 'Menandai pencairan sebagai cair. Saldo anggota akan berkurang dan tercatat di log audit.', 'cta' => 'Cairkan', 'variant' => 'primary', 'icon' => 'banknotes'],
            'reject' => ['title' => 'Tolak Pencairan', 'desc' => 'Menolak pengajuan. Status ditolak bersifat final dan tidak dapat dibuka kembali.', 'cta' => 'Tolak', 'variant' => 'danger', 'icon' => 'x'],
        ][$this->confirmAction] ?? ['title' => '', 'desc' => '', 'cta' => '', 'variant' => 'primary', 'icon' => 'check'];
    }

    public function openConfirm(string $action): void
    {
        $record = $this->record();

        $allowed = match ($action) {
            'approve' => $this->canApprove($record),
            'disburse' => $this->canDisburse($record),
            'reject' => $this->canReject($record),
            default => false,
        };

        abort_unless($allowed, 403);

        $this->confirmAction = $action;
        $this->showConfirm = true;
    }

    public function closeConfirm(): void
    {
        $this->showConfirm = false;
        $this->reset('confirmAction');
    }

    public function performConfirm(): void
    {
        $record = $this->record();
        $action = $this->confirmAction;

        $allowed = match ($action) {
            'approve' => $this->canApprove($record),
            'disburse' => $this->canDisburse($record),
            'reject' => $this->canReject($record),
            default => false,
        };

        abort_unless($allowed, 403);

        $workflow = app(WithdrawalWorkflow::class);

        try {
            match ($action) {
                'approve' => $workflow->approve($record),
                'disburse' => $workflow->disburse($record),
                'reject' => $workflow->reject($record),
            };

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

    public function openReverse(): void
    {
        $record = $this->record();
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
        $record = $this->record();
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
            'withdrawal_date' => 'Tanggal Pengajuan',
            'disbursement_method' => 'Jenis Pencairan',
            'status' => 'Status',
            'period_year' => 'Tahun Program',
            'approved_by' => 'Disetujui Oleh',
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
            'savings_type' => Resource::WITHDRAWAL_TYPES[$value] ?? (string) $value,
            'disbursement_method' => Resource::DISBURSEMENT_METHODS[$value] ?? (string) $value,
            'status' => Resource::STATUSES[$value] ?? (string) $value,
            default => $this->defaultFormatAuditFieldValue($key, $value),
        };
    }

    public function render(): View
    {
        $withdrawal = SavingsWithdrawal::with(['member.agency', 'recordedBy', 'approvedBy', 'reversalOf'])
            ->findOrFail($this->withdrawalId);

        $activities = $withdrawal->activities()->with('causer')->latest()->paginate(10);

        $selectedActivity = $this->auditId
            ? $withdrawal->activities()->with('causer')->find($this->auditId)
            : null;

        // Refund pelunasan (swp+tab, related_loan_id sama) = satu entri logis (D2):
        // tampilkan rincian per komponen + total gabungan di detail.
        $isRefund = Resource::isLoanRefund($withdrawal);

        return view('livewire.savings.withdrawal.savings-withdrawal-detail', [
            'withdrawal' => $withdrawal,
            'typeLabel' => $isRefund ? 'Pengembalian Pelunasan' : (Resource::WITHDRAWAL_TYPES[$withdrawal->savings_type] ?? $withdrawal->savings_type),
            'typeColor' => $isRefund ? 'warning' : Resource::typeColor($withdrawal->savings_type),
            'statusLabel' => Resource::STATUSES[$withdrawal->status] ?? $withdrawal->status,
            'isRefund' => $isRefund,
            'refundSwp' => $isRefund ? Resource::pairAmount($withdrawal, 'swp') : null,
            'refundTab' => $isRefund ? Resource::pairAmount($withdrawal, 'tabungan_berjangka') : null,
            'refundTotal' => $isRefund ? Resource::pairTotal($withdrawal) : null,
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Pencairan']);
    }
}
