<?php

namespace App\Livewire\Loan\Blacklist;

use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\LoanBlacklist;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class LoanBlacklistDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $blacklistId;

    public bool $showRelease = false;

    public function mount(LoanBlacklist $blacklist): void
    {
        $this->authorize('view', $blacklist);

        $this->blacklistId = $blacklist->id;
    }

    public function canRelease(LoanBlacklist $record): bool
    {
        return $record->is_active
            && (auth()->user()?->can('update', $record) ?? false);
    }

    public function openRelease(): void
    {
        $record = LoanBlacklist::findOrFail($this->blacklistId);

        abort_unless($this->canRelease($record), 403);

        $this->showRelease = true;
    }

    public function closeRelease(): void
    {
        $this->showRelease = false;
    }

    public function performRelease(): void
    {
        $record = LoanBlacklist::findOrFail($this->blacklistId);

        abort_unless($this->canRelease($record), 403);

        $record->update([
            'is_active' => false,
            'released_at' => now()->toDateString(),
        ]);

        $this->closeRelease();

        $this->dispatch('toast', type: 'success', message: 'Blacklist dilepas — anggota kembali dapat mengajukan pinjaman.');
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'member_id' => 'Anggota',
            'reason' => 'Alasan',
            'is_active' => 'Aktif',
            'blacklisted_at' => 'Tgl Blacklist',
            'released_at' => 'Tgl Dilepas',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    public function render(): View
    {
        $blacklist = LoanBlacklist::with(['member.agency', 'recordedBy'])->findOrFail($this->blacklistId);

        $activities = $blacklist->activities()->with('causer')->latest()->paginate(8);

        $selectedActivity = $this->auditId
            ? $blacklist->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.loan.blacklist.loan-blacklist-detail', [
            'blacklist' => $blacklist,
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Blacklist']);
    }
}
