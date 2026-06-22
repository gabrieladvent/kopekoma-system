<?php

namespace App\Livewire\Master\Agency;

use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class AgencyDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $agencyId;

    public bool $editing = false;

    public string $agency_code = '';

    public string $agency_name = '';

    public ?string $address = null;

    public ?string $payroll_treasurer = null;

    public ?string $pic_phone_number = null;

    public string $statusForm = 'Aktif';

    public function mount(Agency $agency): void
    {
        $this->authorize('view', $agency);
        $this->agencyId = $agency->id;
    }

    protected function rules(): array
    {
        return [
            'agency_code' => ['required', 'string', 'max:10', Rule::unique('agencies', 'agency_code')->ignore($this->agencyId)],
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

    public function startEdit(): void
    {
        $agency = Agency::findOrFail($this->agencyId);
        $this->authorize('update', $agency);

        $this->agency_code = $agency->agency_code;
        $this->agency_name = $agency->agency_name;
        $this->address = $agency->address;
        $this->payroll_treasurer = $agency->payroll_treasurer;
        $this->pic_phone_number = $this->localPhone($agency->pic_phone_number);
        $this->statusForm = $agency->status;
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $agency = Agency::findOrFail($this->agencyId);
        $this->authorize('update', $agency);

        $validated = $this->validate();

        $agency->update([
            'agency_code' => $validated['agency_code'],
            'agency_name' => $validated['agency_name'],
            'address' => $validated['address'] ?: null,
            'payroll_treasurer' => $validated['payroll_treasurer'] ?: null,
            'pic_phone_number' => $this->normalizePhone($validated['pic_phone_number'] ?? null),
            'status' => $validated['statusForm'],
        ]);

        $this->editing = false;
        $this->dispatch('toast', type: 'success', message: 'OPD diperbarui.');
    }

    public function delete()
    {
        $agency = Agency::findOrFail($this->agencyId);
        $this->authorize('delete', $agency);

        if ($agency->members()->exists()) {
            $this->dispatch('toast', type: 'danger', message: 'Tidak bisa dihapus: masih ada anggota pada OPD ini.');

            return null;
        }

        $agency->delete();
        session()->flash('toast', ['type' => 'success', 'message' => 'OPD dihapus.']);

        return $this->redirectRoute('master.agencies', navigate: true);
    }

    public function generateCode(): void
    {
        do {
            $code = 'OPD'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Agency::where('agency_code', $code)->exists());

        $this->agency_code = $code;
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

    protected function auditFieldLabel(string $key): string
    {
        return [
            'agency_code' => 'Kode OPD',
            'agency_name' => 'Nama OPD / Instansi',
            'address' => 'Alamat',
            'payroll_treasurer' => 'Bendahara Gaji',
            'pic_phone_number' => 'No. HP PIC',
            'status' => 'Status',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    public function render(): View
    {
        $agency = Agency::withCount('members')->findOrFail($this->agencyId);

        $activities = $agency->activities()->with('causer')->latest()->paginate(10);
        $selectedActivity = $this->auditId
            ? $agency->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.master.agency.agency-detail', [
            'agency' => $agency,
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
            'treasurers' => User::query()->orderBy('name')->pluck('name', 'name'),
        ])->layout('components.layouts.app', ['title' => 'Detail OPD / Instansi']);
    }
}
