<?php

namespace App\Livewire\Loan\Blacklist;

use App\Livewire\Concerns\WithMemberPicker;
use App\Models\LoanBlacklist;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LoanBlacklists extends Component
{
    use WithMemberPicker;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $active = 'all'; // all | 1 | 0

    // Modal tambah
    public bool $showCreate = false;

    public ?string $reason = null;

    public ?string $blacklisted_at = null;

    // Modal lepas
    public bool $showRelease = false;

    public ?string $releaseId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', LoanBlacklist::class);
        $this->blacklisted_at = now()->toDateString();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedActive(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'active');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->active !== 'all';
    }

    /**
     * Override picker: sembunyikan anggota yang sudah punya blacklist AKTIF
     * supaya tidak bisa didaftarkan dua kali selama belum dilepas.
     *
     * @return Collection<int, Member>
     */
    public function memberResults(): Collection
    {
        $blacklisted = LoanBlacklist::query()->where('is_active', true)->pluck('member_id');

        return Member::query()
            ->with(['agency:id,agency_name', 'grade:id,code'])
            ->whereNotIn('id', $blacklisted)
            ->when($this->memberSearch !== '', function ($query) {
                $term = '%'.$this->memberSearch.'%';
                $query->where(fn ($subQuery) => $subQuery->where('full_name', 'like', $term)
                    ->orWhere('member_number', 'like', $term)
                    ->orWhere('nik', 'like', $term)
                    ->orWhere('nip', 'like', $term));
            })
            ->orderBy('member_number')
            ->limit(15)
            ->get();
    }

    public function openCreate(): void
    {
        $this->authorize('create', LoanBlacklist::class);

        $this->reset('member_id', 'selectedMemberLabel', 'memberSearch', 'reason');
        $this->blacklisted_at = now()->toDateString();
        $this->resetErrorBag();
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
    }

    public function store(): void
    {
        $this->authorize('create', LoanBlacklist::class);

        $this->validate(
            [
                'member_id' => ['required', 'exists:members,id'],
                'reason' => ['required', 'string', 'min:5', 'max:65535'],
                'blacklisted_at' => ['required', 'date'],
            ],
            [
                'member_id.required' => 'Anggota wajib dipilih.',
                'reason.required' => 'Alasan wajib diisi.',
                'reason.min' => 'Alasan minimal 5 karakter.',
            ],
            ['member_id' => 'anggota', 'reason' => 'alasan', 'blacklisted_at' => 'tanggal blacklist'],
        );

        if (LoanBlacklist::query()->where('member_id', $this->member_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'member_id' => 'Anggota ini masih dalam blacklist aktif — lepas dulu sebelum menandai ulang.',
            ]);
        }

        LoanBlacklist::create([
            'member_id' => $this->member_id,
            'reason' => $this->reason,
            'blacklisted_at' => $this->blacklisted_at,
            'is_active' => true,
            'recorded_by' => auth()->id(),
        ]);

        $this->closeCreate();
        $this->resetPage();
        $this->dispatch('toast', type: 'success', message: 'Anggota ditandai blacklist pinjaman.');
    }

    public function openRelease(string $id): void
    {
        $record = LoanBlacklist::findOrFail($id);
        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        $this->releaseId = $id;
        $this->showRelease = true;
    }

    public function closeRelease(): void
    {
        $this->showRelease = false;
        $this->reset('releaseId');
    }

    public function performRelease(): void
    {
        $record = LoanBlacklist::findOrFail($this->releaseId);
        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        if ($record->is_active) {
            $record->update([
                'is_active' => false,
                'released_at' => now()->toDateString(),
            ]);
        }

        $this->closeRelease();
        $this->dispatch('toast', type: 'success', message: 'Blacklist dilepas — anggota kembali dapat mengajukan pinjaman.');
    }

    public function render(): View
    {
        $blacklists = LoanBlacklist::query()
            ->with(['member:id,member_number,full_name', 'recordedBy:id,name'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->whereHas('member', fn ($m) => $m->where('full_name', 'like', $term)
                    ->orWhere('member_number', 'like', $term));
            })
            ->when($this->active !== 'all', fn ($q) => $q->where('is_active', (int) $this->active))
            ->latest('created_at')
            ->paginate(10);

        return view('livewire.loan.blacklist.loan-blacklists', [
            'blacklists' => $blacklists,
        ])->layout('components.layouts.app', ['title' => 'Blacklist Pinjaman']);
    }
}
