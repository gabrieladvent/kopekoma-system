<?php

namespace App\Livewire\Master\Grade;

use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\Grade;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class GradeDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public int $gradeId;

    // Edit inline (default read-only)
    public bool $editing = false;

    public string $code = '';

    public string $name = '';

    public ?int $mandatory_savings_amount = null;

    public bool $is_active = true;

    public function mount(Grade $grade): void
    {
        $this->authorize('view', $grade);
        $this->gradeId = $grade->id;
    }

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:15', Rule::unique('grades', 'code')->ignore($this->gradeId)],
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

    public function startEdit(): void
    {
        $grade = Grade::findOrFail($this->gradeId);
        $this->authorize('update', $grade);

        $this->code = $grade->code;
        $this->name = $grade->name;
        $this->mandatory_savings_amount = $grade->mandatory_savings_amount;
        $this->is_active = $grade->is_active;
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
        $grade = Grade::findOrFail($this->gradeId);
        $this->authorize('update', $grade);

        $grade->update($this->validate());
        $this->editing = false;
        $this->dispatch('toast', type: 'success', message: 'Golongan diperbarui.');
    }

    public function delete()
    {
        $grade = Grade::findOrFail($this->gradeId);
        $this->authorize('delete', $grade);

        if ($grade->members()->exists()) {
            $this->dispatch('toast', type: 'danger', message: 'Tidak bisa dihapus: masih ada anggota pada golongan ini.');

            return null;
        }

        $grade->delete();
        session()->flash('toast', ['type' => 'success', 'message' => 'Golongan dihapus.']);

        return $this->redirectRoute('master.grades', navigate: true);
    }

    public function generateCode(): void
    {
        do {
            $code = 'GOL-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Grade::where('code', $code)->exists());

        $this->code = $code;
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'code' => 'Kode',
            'name' => 'Nama Golongan',
            'mandatory_savings_amount' => 'Simpanan Wajib',
            'is_active' => 'Status Aktif',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    protected function formatAuditFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'mandatory_savings_amount' => 'Rp '.number_format((int) $value, 0, ',', '.'),
            'is_active' => ((bool) $value) ? 'Aktif' : 'Nonaktif',
            default => $this->defaultFormatAuditFieldValue($key, $value),
        };
    }

    public function render(): View
    {
        $grade = Grade::withCount('members')->findOrFail($this->gradeId);

        $activities = $grade->activities()->with('causer')->latest()->paginate(10);
        $selectedActivity = $this->auditId
            ? $grade->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.master.grade.grade-detail', [
            'grade' => $grade,
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Golongan']);
    }
}
