<?php

namespace App\Livewire\Master\Agency;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Agencies extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $agency_code = '';

    public string $agency_name = '';

    public ?string $address = null;

    public ?string $payroll_treasurer = null;

    public ?string $pic_phone_number = null;

    public string $statusForm = 'Aktif';

    public function mount(): void
    {
        $this->authorize('viewAny', Agency::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->status !== 'all';
    }

    protected function rules(): array
    {
        return [
            'agency_code' => ['required', 'string', 'max:10', Rule::unique('agencies', 'agency_code')->ignore($this->editingId)],
            'agency_name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'payroll_treasurer' => ['nullable', 'string', 'max:100'],
            'pic_phone_number' => ['nullable', 'string', 'max:15'],
            'statusForm' => ['required', Rule::in(['Aktif', 'Non-Aktif'])],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'agency_code' => 'kode OPD',
            'agency_name' => 'nama OPD',
            'address' => 'alamat',
            'payroll_treasurer' => 'bendahara gaji',
            'pic_phone_number' => 'no. HP PIC',
            'statusForm' => 'status',
        ];
    }

    public function create(): void
    {
        $this->authorize('create', Agency::class);

        $this->resetForm();

        $this->agency_code = $this->makeCode();

        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $agency = Agency::findOrFail($id);

        $this->authorize('update', $agency);

        $this->editingId = $agency->id;

        $this->agency_code = $agency->agency_code;

        $this->agency_name = $agency->agency_name;

        $this->address = $agency->address;

        $this->payroll_treasurer = $agency->payroll_treasurer;

        $this->pic_phone_number = $this->localPhone($agency->pic_phone_number);

        $this->statusForm = $agency->status;

        $this->resetErrorBag();

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->editingId
            ? $this->authorize('update', Agency::findOrFail($this->editingId))
            : $this->authorize('create', Agency::class);

        $validated = $this->validate();

        $data = [
            'agency_code' => $validated['agency_code'],
            'agency_name' => $validated['agency_name'],
            'address' => $validated['address'] ?: null,
            'payroll_treasurer' => $validated['payroll_treasurer'] ?: null,
            'pic_phone_number' => $this->normalizePhone($validated['pic_phone_number'] ?? null),
            'status' => $validated['statusForm'],
        ];

        if ($this->editingId) {
            Agency::findOrFail($this->editingId)->update($data);

            $this->dispatch('toast', type: 'success', message: 'OPD diperbarui.');

        } else {
            Agency::create($data);

            $this->dispatch('toast', type: 'success', message: 'OPD ditambahkan.');
        }

        $this->showForm = false;

        $this->resetForm();
    }

    public function toggleActive(string $id): void
    {
        $agency = Agency::findOrFail($id);

        $this->authorize('update', $agency);

        $next = $agency->status === 'Aktif' ? 'Non-Aktif' : 'Aktif';

        $agency->update(['status' => $next]);

        $this->dispatch('toast', type: 'success', message: $next === 'Aktif' ? 'OPD diaktifkan.' : 'OPD dinonaktifkan.');
    }

    public function delete(string $id): void
    {
        $agency = Agency::findOrFail($id);

        $this->authorize('delete', $agency);

        if ($agency->members()->exists()) {
            $this->dispatch('toast', type: 'danger', message: 'Tidak bisa dihapus: masih ada anggota pada OPD ini.');

            return;
        }

        $agency->delete();

        $this->dispatch('toast', type: 'success', message: 'OPD dihapus.');
    }

    public function generateCode(): void
    {
        $this->agency_code = $this->makeCode();
    }

    private function makeCode(): string
    {
        do {
            $code = 'OPD'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Agency::where('agency_code', $code)->exists());

        return $code;
    }

    private function normalizePhone(?string $state): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $state);

        $digits = preg_replace('/^62/', '', (string) $digits);

        $digits = ltrim((string) $digits, '0');

        return $digits === '' ? null : '+62'.$digits;
    }

    private function localPhone(?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $state);

        $digits = preg_replace('/^62/', '', (string) $digits);

        return ltrim((string) $digits, '0') ?: null;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'agency_code', 'agency_name', 'address', 'payroll_treasurer', 'pic_phone_number', 'statusForm');

        $this->statusForm = 'Aktif';

        $this->resetErrorBag();
    }

    public function render(): View
    {
        $agencies = Agency::query()
            ->withCount('members')
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(fn ($subQuery) => $subQuery->where('agency_code', 'like', $term)
                    ->orWhere('agency_name', 'like', $term)
                    ->orWhere('payroll_treasurer', 'like', $term));
            })
            ->when($this->status === 'active', fn ($query) => $query->where('status', 'Aktif'))
            ->when($this->status === 'inactive', fn ($query) => $query->where('status', 'Non-Aktif'))
            ->orderBy('agency_code')
            ->paginate(10);

        return view('livewire.master.agency.agencies', [
            'agencies' => $agencies,
            'treasurers' => User::query()->orderBy('name')->pluck('name', 'name'),
        ])->layout('components.layouts.app', ['title' => 'Master Data OPD / Instansi']);
    }
}
