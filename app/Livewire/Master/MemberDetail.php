<?php

namespace App\Livewire\Master;

use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class MemberDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithFileUploads;
    use WithPagination;

    public string $memberId;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(Member $member): void
    {
        $this->authorize('view', $member);
        $this->memberId = $member->id;
    }

    public function uploadDocuments(): void
    {
        $member = Member::findOrFail($this->memberId);
        $this->authorize('update', $member);

        $this->validate([
            'uploads' => ['required', 'array', 'max:10'],
            'uploads.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [], ['uploads.*' => 'berkas']);

        foreach ($this->uploads as $file) {
            $member->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->toMediaCollection('documents');
        }

        $member->logDocumentActivity('Mengunggah '.count($this->uploads).' dokumen.');

        $this->reset('uploads');
        $this->dispatch('toast', type: 'success', message: 'Dokumen diunggah.');
    }

    public function deleteDocument(int $mediaId): void
    {
        $member = Member::findOrFail($this->memberId);
        $this->authorize('update', $member);

        $media = $member->getMedia('documents')->firstWhere('id', $mediaId);

        if ($media) {
            $name = $media->file_name;
            $media->delete();
            $member->logDocumentActivity('Menghapus dokumen '.$name.'.');
        }

        $this->dispatch('toast', type: 'success', message: 'Dokumen dihapus.');
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

        return view('livewire.master.member-detail', [
            'member' => $member,
            'documents' => $member->getMedia('documents'),
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Anggota']);
    }
}
