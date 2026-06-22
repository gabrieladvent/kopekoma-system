<?php

namespace App\Livewire\Master\Member;

use App\Exports\MembersTemplateExport;
use App\Jobs\ImportMembersJob;
use App\Models\Agency;
use App\Models\Grade;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Members extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all'; // all | Aktif | Non-Aktif | Keluar | Meninggal

    #[Url]
    public string $agency = ''; // agency_id

    #[Url]
    public string $grade = ''; // grade_id

    // Import modal
    public bool $showImport = false;

    public $importFile = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Member::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
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

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'agency', 'grade');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->status !== 'all' || $this->agency !== '' || $this->grade !== '';
    }

    /** Hanya Pengurus ke atas yang boleh import/export. Port dari MemberResource. */
    public function canManageImportExport(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'pengurus']) ?? false;
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        abort_unless($this->canManageImportExport(), 403);

        return Excel::download(new MembersTemplateExport, 'template-import-anggota.xlsx');
    }

    public function import(): void
    {
        abort_unless($this->canManageImportExport(), 403);

        $this->validate(
            ['importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']],
            [],
            ['importFile' => 'berkas']
        );

        $path = $this->importFile->store('imports', 'local');

        ImportMembersJob::dispatch($path, 'local', (int) auth()->id());

        $this->showImport = false;
        $this->reset('importFile');
        $this->dispatch('toast', type: 'success', message: 'Berkas diunggah. Import diproses di latar belakang, notifikasi muncul bila selesai.');
    }

    public function delete(string $id): void
    {
        $member = Member::findOrFail($id);
        $this->authorize('delete', $member);

        $member->delete();
        $this->dispatch('toast', type: 'success', message: 'Anggota dihapus.');
    }

    public function render(): View
    {
        $members = Member::query()
            ->with(['agency', 'grade'])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($s) => $s->where('full_name', 'like', $term)
                    ->orWhere('member_number', 'like', $term)
                    ->orWhere('nik', 'like', $term)
                    ->orWhere('nip', 'like', $term));
            })
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->agency !== '', fn ($q) => $q->where('agency_id', $this->agency))
            ->when($this->grade !== '', fn ($q) => $q->where('grade_id', $this->grade))
            ->orderBy('member_number')
            ->paginate(15);

        return view('livewire.master.member.members', [
            'members' => $members,
            'agencyOptions' => Agency::orderBy('agency_name')->pluck('agency_name', 'id'),
            'gradeOptions' => Grade::orderBy('code')->get(['id', 'code', 'name']),
        ])->layout('components.layouts.app', ['title' => 'Master Data Anggota']);
    }
}
