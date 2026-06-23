<?php

namespace App\Livewire\Savings\Holiday;

use App\Models\MemberHolidaySaving;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class HolidayRegistrations extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $year = ''; // period_year

    #[Url]
    public string $active = 'all'; // all | 1 | 0

    public function mount(): void
    {
        $this->authorize('viewAny', MemberHolidaySaving::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedActive(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'year', 'active');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->year !== '' || $this->active !== 'all';
    }

    public function delete(int $id): void
    {
        $registration = MemberHolidaySaving::findOrFail($id);
        $this->authorize('delete', $registration);

        $registration->delete();
        $this->dispatch('toast', type: 'success', message: 'Pendaftaran Hari Raya dihapus.');
    }

    public function render(): View
    {
        $registrations = MemberHolidaySaving::query()
            ->with('member:id,member_number,full_name')
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->whereHas('member', fn ($m) => $m->where('full_name', 'like', $term)
                    ->orWhere('member_number', 'like', $term));
            })
            ->when($this->year !== '', fn ($q) => $q->where('period_year', $this->year))
            ->when($this->active !== 'all', fn ($q) => $q->where('is_active', (int) $this->active))
            ->orderByDesc('period_year')
            ->orderBy('id')
            ->paginate(15);

        return view('livewire.savings.holiday.holiday-registrations', [
            'registrations' => $registrations,
            'yearOptions' => MemberHolidaySaving::query()
                ->distinct()
                ->orderByDesc('period_year')
                ->pluck('period_year', 'period_year'),
        ])->layout('components.layouts.app', ['title' => 'Pendaftaran Hari Raya']);
    }
}
