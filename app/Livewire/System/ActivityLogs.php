<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

class ActivityLogs extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    /** Label event lengkap — selaras dengan Filament FormatsActivity. */
    public const EVENT_LABELS = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
        'approved' => 'Disetujui (ACC)',
        'disbursed' => 'Dicairkan',
        'rejected' => 'Ditolak',
        'reversal' => 'Reversal',
        'batch_potong_gaji' => 'Batch Potong Gaji',
    ];

    /** Warna badge per event (dipetakan ke palet <x-ui.badge>). */
    public const EVENT_COLORS = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
        'restored' => 'primary',
        'approved' => 'primary',
        'disbursed' => 'success',
        'rejected' => 'danger',
        'reversal' => 'danger',
        'batch_potong_gaji' => 'primary',
    ];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $event = '';

    #[Url]
    public string $subject = ''; // subject_type (FQCN)

    #[Url]
    public string $causer = ''; // causer_id

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public function mount(): void
    {
        abort_unless($this->canView(), 403);
    }

    private function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'pengurus']) ?? false;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEvent(): void
    {
        $this->resetPage();
    }

    public function updatedSubject(): void
    {
        $this->resetPage();
    }

    public function updatedCauser(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedUntil(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'event', 'subject', 'causer', 'from', 'until');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->event !== '' || $this->subject !== ''
            || $this->causer !== '' || $this->from !== '' || $this->until !== '';
    }

    // Override label/warna event memakai set lengkap.
    public function auditEventLabel(?string $event): string
    {
        return self::EVENT_LABELS[$event] ?? ucfirst((string) $event);
    }

    public function auditEventColor(?string $event): string
    {
        return self::EVENT_COLORS[$event] ?? 'neutral';
    }

    public function render(): View
    {
        $activities = Activity::query()
            ->with('causer')
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($s) => $s->where('description', 'like', $term)
                    ->orWhereHas('causer', fn ($c) => $c->where('name', 'like', $term)));
            })
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->subject !== '', fn ($q) => $q->where('subject_type', $this->subject))
            ->when($this->causer !== '', fn ($q) => $q->where('causer_id', $this->causer))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->until !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->until))
            ->latest()
            ->paginate(10);

        $selectedActivity = $this->auditId
            ? Activity::with('causer')->find($this->auditId)
            : null;

        return view('livewire.system.activity-logs', [
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
            'eventOptions' => self::EVENT_LABELS,
            'subjectOptions' => Activity::query()->select('subject_type')->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'causerOptions' => User::orderBy('name')->pluck('name', 'id'),
        ])->layout('components.layouts.app', ['title' => 'Log Aktivitas']);
    }
}
