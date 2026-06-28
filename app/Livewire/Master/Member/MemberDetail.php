<?php

namespace App\Livewire\Master\Member;

use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class MemberDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $memberId;

    public function mount(Member $member): void
    {
        $this->authorize('view', $member);
        $this->memberId = $member->id;
    }

    public function delete()
    {
        $member = Member::findOrFail($this->memberId);
        $this->authorize('delete', $member);

        $member->delete();
        session()->flash('toast', ['type' => 'success', 'message' => 'Anggota dihapus.']);

        return $this->redirectRoute('master.members', navigate: true);
    }

    public function canManageImportExport(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'pengurus']) ?? false;
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'full_name' => 'Nama Lengkap',
            'nik' => 'NIK',
            'nip' => 'NIP',
            'birth_place' => 'Tempat Lahir',
            'birth_date' => 'Tanggal Lahir',
            'gender' => 'Jenis Kelamin',
            'agency_id' => 'OPD / Instansi',
            'grade_id' => 'Golongan',
            'employment_status' => 'Status Kepegawaian',
            'position' => 'Jabatan',
            'mandatory_savings_amount' => 'Simpanan Wajib',
            'payroll_account_number' => 'No. Rekening Gaji',
            'bank_name' => 'Nama Bank',
            'phone_number' => 'No. HP',
            'address' => 'Alamat',
            'join_date' => 'Tanggal Bergabung',
            'exit_date' => 'Tanggal Keluar',
            'heir_name' => 'Nama Ahli Waris',
            'heir_relationship' => 'Hubungan Ahli Waris',
            'heir_phone_number' => 'No. HP Ahli Waris',
            'status' => 'Status',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    public function render(): View
    {
        $member = Member::with(['agency', 'grade'])->findOrFail($this->memberId);

        $activities = $member->activities()->with('causer')->latest()->paginate(10);
        $selectedActivity = $this->auditId
            ? $member->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.master.member.member-detail', [
            'member' => $member,
            'documents' => $member->getMedia('documents'),
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Anggota']);
    }
}
