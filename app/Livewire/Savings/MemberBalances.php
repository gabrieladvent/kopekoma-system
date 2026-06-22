<?php

namespace App\Livewire\Savings;

use App\Models\Agency;
use App\Models\Grade;
use App\Models\Member;
use App\Services\SavingsBalanceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class MemberBalances extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $agency = '';

    #[Url]
    public string $grade = '';

    #[Url]
    public string $status = 'Aktif'; // default Aktif (mirror Filament)

    public function mount(): void
    {
        // Mirror MemberSavingsBalanceResource (Shield permission atas resource ini).
        abort_unless(auth()->user()?->can('view_any_member::savings::balance') ?? false, 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAgency(): void
    {
        $this->resetPage();
    }

    public function updatedGrade(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'agency', 'grade');
        $this->status = 'all';
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->agency !== '' || $this->grade !== '' || $this->status !== 'Aktif';
    }

    public function render(): View
    {
        $members = Member::query()
            ->with(['agency:id,agency_name', 'grade:id,code'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($s) => $s->where('full_name', 'like', $term)
                    ->orWhere('member_number', 'like', $term));
            })
            ->when($this->agency !== '', fn ($q) => $q->where('agency_id', $this->agency))
            ->when($this->grade !== '', fn ($q) => $q->where('grade_id', $this->grade))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->orderBy('member_number')
            ->paginate(15);

        $service = app(SavingsBalanceService::class);

        // Saldo computed-on-read per anggota (allBalances ≈ 3 query/anggota).
        $rows = $members->getCollection()->map(function (Member $member) use ($service) {
            $all = $service->allBalances($member);
            $holiday = array_reduce(
                $all['hari_raya'],
                fn (string $carry, string $balance): string => bcadd($carry, $balance, 2),
                '0',
            );

            return [
                'member' => $member,
                'pokok' => $all['pokok'],
                'wajib' => $all['wajib'],
                'sukarela' => $all['sukarela'],
                'hari_raya' => $holiday,
                'wajib_belanja' => $all['wajib_belanja'],
                'total' => $service->totalBalance($member),
            ];
        });

        return view('livewire.savings.member-balances', [
            'members' => $members,
            'rows' => $rows,
            'agencyOptions' => Agency::orderBy('agency_name')->pluck('agency_name', 'id'),
            'gradeOptions' => Grade::orderBy('code')->get(['id', 'code', 'name']),
        ])->layout('components.layouts.app', ['title' => 'Saldo Anggota']);
    }
}
