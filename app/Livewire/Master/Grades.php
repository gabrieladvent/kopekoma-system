<?php

namespace App\Livewire\Master;

use App\Models\Grade;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Grades extends Component
{
    use WithPagination;

    /** Label & warna event activity-log (mirror App\Filament\Concerns\FormatsActivity). */
    public const EVENT_LABELS = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
    ];

    public const EVENT_COLORS = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
        'restored' => 'primary',
    ];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all'; // all | active | inactive

    // Form modal (create/edit)
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public ?int $mandatory_savings_amount = null;

    public bool $is_active = true;

    // Detail modal
    public bool $showDetail = false;

    public ?int $detailId = null;

    public string $detailTab = 'info';

    public function mount(): void
    {
        $this->authorize('viewAny', Grade::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:15', Rule::unique('grades', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:50'],
            'mandatory_savings_amount' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama golongan',
            'mandatory_savings_amount' => 'simpanan wajib',
        ];
    }

    public function create(): void
    {
        $this->authorize('create', Grade::class);

        $this->resetForm();
        $this->code = $this->makeCode();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $grade = Grade::findOrFail($id);
        $this->authorize('update', $grade);

        $this->editingId = $grade->id;
        $this->code = $grade->code;
        $this->name = $grade->name;
        $this->mandatory_savings_amount = $grade->mandatory_savings_amount;
        $this->is_active = $grade->is_active;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->editingId
            ? $this->authorize('update', Grade::findOrFail($this->editingId))
            : $this->authorize('create', Grade::class);

        $data = $this->validate();

        if ($this->editingId) {
            Grade::findOrFail($this->editingId)->update($data);
            $this->dispatch('toast', type: 'success', message: 'Golongan diperbarui.');
        } else {
            Grade::create($data);
            $this->dispatch('toast', type: 'success', message: 'Golongan ditambahkan.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $grade = Grade::findOrFail($id);
        $this->authorize('update', $grade);

        $grade->update(['is_active' => ! $grade->is_active]);
        $this->dispatch('toast', type: 'success', message: $grade->is_active ? 'Golongan diaktifkan.' : 'Golongan dinonaktifkan.');
    }

    public function delete(int $id): void
    {
        $grade = Grade::findOrFail($id);
        $this->authorize('delete', $grade);

        if ($grade->members()->exists()) {
            $this->dispatch('toast', type: 'danger', message: 'Tidak bisa dihapus: masih ada anggota pada golongan ini.');

            return;
        }

        $grade->delete();
        $this->dispatch('toast', type: 'success', message: 'Golongan dihapus.');
    }

    public function show(int $id): void
    {
        $grade = Grade::findOrFail($id);
        $this->authorize('view', $grade);

        $this->detailId = $grade->id;
        $this->detailTab = 'info';
        $this->showDetail = true;
    }

    public function generateCode(): void
    {
        $this->code = $this->makeCode();
    }

    /** Kode unik golongan (format GOL-0001). Port dari GradeResource. */
    private function makeCode(): string
    {
        do {
            $code = 'GOL-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Grade::where('code', $code)->exists());

        return $code;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'code', 'name', 'mandatory_savings_amount', 'is_active');
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $grades = Grade::query()
            ->withCount('members')
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($s) => $s->where('code', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('code')
            ->paginate(10);

        $detail = $this->detailId ? Grade::withCount('members')->find($this->detailId) : null;
        $activities = $detail
            ? $detail->activities()->with('causer')->latest()->limit(50)->get()
            : collect();

        return view('livewire.master.grades', [
            'grades' => $grades,
            'detail' => $detail,
            'activities' => $activities,
        ])->layout('components.layouts.app', ['title' => 'Master Data Golongan']);
    }
}
