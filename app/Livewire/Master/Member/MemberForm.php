<?php

namespace App\Livewire\Master\Member;

use App\Models\Agency;
use App\Models\Grade;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;

class MemberForm extends Component
{
    use WithFileUploads;

    public ?string $memberId = null;

    /**
     * Antrean berkas yang akan dilampirkan saat form disimpan (terakumulasi
     * lintas beberapa kali pilih berkas — lihat updatedNewUploads()).
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $uploads = [];

    /**
     * Input berkas sementara (transient). Tiap kali user memilih berkas,
     * isinya digabung ke $uploads lalu dikosongkan, sehingga user bisa
     * menambah berkas dalam beberapa kali pilih (multi-dokumen).
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $newUploads = [];

    public const MAX_DOCUMENTS = 10;

    // Identitas
    public string $member_number = '';

    public string $full_name = '';

    public string $nik = '';

    public ?string $nip = null;

    public string $birth_place = '';

    public ?string $birth_date = null;

    public string $gender = 'L';

    // Instansi & golongan
    public ?string $agency_id = null;

    public ?string $grade_id = null;

    public string $employment_status = 'ASN';

    public ?string $position = null;

    // Keuangan
    public ?int $mandatory_savings_amount = null;

    public string $payroll_account_number = '';

    public ?string $bank_name = null;

    // Kontak
    public string $phone_number = '';

    public string $address = '';

    // Keanggotaan
    public ?string $join_date = null;

    public string $statusForm = 'Aktif';

    public ?string $exit_date = null;

    // Ahli waris
    public string $heir_name = '';

    public ?string $heir_relationship = null;

    public string $heir_phone_number = '';

    public function mount(?Member $member = null): void
    {
        if ($member && $member->exists) {
            $this->authorize('update', $member);

            $this->memberId = $member->id;
            $this->member_number = $member->member_number;
            $this->full_name = $member->full_name;
            $this->nik = $member->nik;
            $this->nip = $member->nip;
            $this->birth_place = $member->birth_place;
            $this->birth_date = $member->birth_date?->toDateString();
            $this->gender = $member->gender;
            $this->agency_id = $member->agency_id;
            $this->grade_id = $member->grade_id;
            $this->employment_status = $member->employment_status;
            $this->position = $member->position;
            $this->mandatory_savings_amount = (int) $member->mandatory_savings_amount;
            $this->payroll_account_number = $member->payroll_account_number;
            $this->bank_name = $member->bank_name;
            $this->phone_number = $this->localPhone($member->phone_number);
            $this->address = $member->address;
            $this->join_date = $member->join_date?->toDateString();
            $this->statusForm = $member->status;
            $this->exit_date = $member->exit_date?->toDateString();
            $this->heir_name = $member->heir_name;
            $this->heir_relationship = $member->heir_relationship;
            $this->heir_phone_number = $this->localPhone($member->heir_phone_number);

            return;
        }

        $this->authorize('create', Member::class);
        $this->member_number = Member::generateMemberNumber();
        $this->join_date = now()->toDateString();
    }

    /** Snapshot nominal simpanan wajib dari golongan saat membuat anggota baru. */
    public function updatedGradeId(?string $value): void
    {
        if (! filled($value)) {
            return;
        }

        // Re-snapshot nominal simpanan wajib dari golongan terpilih. Saat create
        // selalu; saat edit hanya bila user berhak override — kalau tidak,
        // nominal tak akan ikut tersimpan, jadi jangan diubah agar tidak menyesatkan.
        if ($this->memberId !== null && ! $this->canOverrideMandatory()) {
            return;
        }

        $grade = Grade::find($value);

        if ($grade) {
            $this->mandatory_savings_amount = (int) $grade->mandatory_savings_amount;
        }
    }

    /** Hanya Pengurus ke atas yang boleh override nominal simpanan wajib. */
    public function canOverrideMandatory(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'pengurus']) ?? false;
    }

    protected function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:100'],
            'nik' => ['required', 'digits:16', Rule::unique('members', 'nik')->ignore($this->memberId)->withoutTrashed()],
            'nip' => [Rule::requiredIf($this->employment_status === 'ASN'), 'nullable', 'string', 'max:25'],
            'birth_place' => ['required', 'string', 'max:50'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'gender' => ['required', Rule::in(['L', 'P'])],
            'agency_id' => ['required', 'exists:agencies,id'],
            'grade_id' => ['required', 'exists:grades,id'],
            'employment_status' => ['required', Rule::in(['ASN', 'Honorer'])],
            'position' => ['nullable', 'string', 'max:100'],
            'mandatory_savings_amount' => ['required', 'integer', 'min:0'],
            'payroll_account_number' => ['required', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:50'],
            'phone_number' => ['required', 'string', 'max:15', 'regex:/^[0-9]{8,15}$/'],
            'address' => ['required', 'string'],
            'join_date' => ['required', 'date'],
            'statusForm' => ['required', Rule::in(['Aktif', 'Non-Aktif', 'Keluar', 'Meninggal'])],
            'exit_date' => [Rule::requiredIf(in_array($this->statusForm, ['Keluar', 'Meninggal'], true)), 'nullable', 'date'],
            'heir_name' => ['required', 'string', 'max:100'],
            'heir_relationship' => ['required', Rule::in(array_keys(Member::HEIR_RELATIONSHIPS))],
            'heir_phone_number' => ['required', 'string', 'max:15', 'regex:/^[0-9]{8,15}$/'],
            'uploads' => ['nullable', 'array', 'max:'.self::MAX_DOCUMENTS],
            'uploads.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'newUploads.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone_number.regex' => 'No. HP hanya boleh berisi angka (8–15 digit), tanpa huruf atau simbol.',
            'heir_phone_number.regex' => 'No. HP ahli waris hanya boleh berisi angka (8–15 digit), tanpa huruf atau simbol.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'full_name' => 'nama lengkap',
            'nik' => 'NIK',
            'nip' => 'NIP',
            'birth_place' => 'tempat lahir',
            'birth_date' => 'tanggal lahir',
            'gender' => 'jenis kelamin',
            'agency_id' => 'OPD / instansi',
            'grade_id' => 'golongan',
            'employment_status' => 'status kepegawaian',
            'position' => 'jabatan',
            'mandatory_savings_amount' => 'simpanan wajib',
            'payroll_account_number' => 'no. rekening gaji',
            'bank_name' => 'nama bank',
            'phone_number' => 'no. HP',
            'address' => 'alamat',
            'join_date' => 'tanggal bergabung',
            'statusForm' => 'status',
            'exit_date' => 'tanggal keluar',
            'heir_name' => 'nama ahli waris',
            'heir_relationship' => 'hubungan ahli waris',
            'heir_phone_number' => 'no. HP ahli waris',
            'uploads.*' => 'berkas',
            'newUploads.*' => 'berkas',
        ];
    }

    /**
     * Setiap kali user memilih berkas (bisa beberapa sekaligus), validasi lalu
     * gabungkan ke antrean $uploads sehingga user dapat menambah dokumen dalam
     * beberapa kali pilih. Ini yang memungkinkan multi-dokumen sebenarnya.
     */
    public function updatedNewUploads(): void
    {
        $this->validate([
            'newUploads.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], attributes: ['newUploads.*' => 'berkas']);

        foreach ($this->newUploads as $file) {
            if (count($this->uploads) >= self::MAX_DOCUMENTS) {
                $this->dispatch('toast', type: 'warning', message: 'Maksimal '.self::MAX_DOCUMENTS.' berkas.');
                break;
            }
            $this->uploads[] = $file;
        }

        $this->newUploads = [];
    }

    /** Buang satu berkas dari antrean unggah sebelum form disimpan. */
    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    /** Hapus dokumen yang sudah tersimpan (hanya relevan saat edit). */
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

    public function save()
    {
        $this->memberId
            ? $this->authorize('update', Member::findOrFail($this->memberId))
            : $this->authorize('create', Member::class);

        try {
            $validated = $this->validate();
        } catch (ValidationException $e) {
            // Beri sinyal jelas ke user: toast + scroll ke isian pertama yang
            // bermasalah, supaya kesalahan tidak "diam-diam" di tengah form panjang.
            $this->dispatch('toast', type: 'danger', message: 'Ada isian yang belum benar. Periksa bagian yang ditandai merah.');
            $this->dispatch('scroll-to-error');

            throw $e;
        }

        $exit = in_array($validated['statusForm'], ['Keluar', 'Meninggal'], true)
            ? $validated['exit_date']
            : null;

        $data = [
            'full_name' => $validated['full_name'],
            'nik' => $validated['nik'],
            'nip' => $validated['nip'] ?: null,
            'birth_place' => $validated['birth_place'],
            'birth_date' => $validated['birth_date'],
            'gender' => $validated['gender'],
            'agency_id' => $validated['agency_id'],
            'grade_id' => $validated['grade_id'],
            'employment_status' => $validated['employment_status'],
            'position' => $validated['position'] ?: null,
            'payroll_account_number' => $validated['payroll_account_number'],
            'bank_name' => $validated['bank_name'] ?: null,
            'phone_number' => $this->normalizePhone($validated['phone_number']),
            'address' => $validated['address'],
            'join_date' => $validated['join_date'],
            'status' => $validated['statusForm'],
            'exit_date' => $exit,
            'heir_name' => $validated['heir_name'],
            'heir_relationship' => $validated['heir_relationship'],
            'heir_phone_number' => $this->normalizePhone($validated['heir_phone_number']),
        ];

        // Nominal simpanan wajib hanya ditulis saat membuat (snapshot) atau
        // saat Pengurus ke atas meng-override secara eksplisit.
        if ($this->memberId === null || $this->canOverrideMandatory()) {
            $data['mandatory_savings_amount'] = $validated['mandatory_savings_amount'];
        }

        if ($this->memberId) {
            $member = Member::findOrFail($this->memberId);
            $member->update($data);
            session()->flash('toast', ['type' => 'success', 'message' => 'Data anggota diperbarui.']);
        } else {
            $member = Member::create($data);
            session()->flash('toast', ['type' => 'success', 'message' => 'Anggota baru ditambahkan.']);
        }

        $this->attachUploads($member);

        return $this->redirectRoute('master.members.show', $member, navigate: true);
    }

    /** Lampirkan berkas yang diunggah ke koleksi media anggota. */
    private function attachUploads(Member $member): void
    {
        if (empty($this->uploads)) {
            return;
        }

        $attached = 0;
        $rejected = 0;

        foreach ($this->uploads as $file) {
            try {
                $member->addMedia($file->getRealPath())
                    ->usingFileName($file->getClientOriginalName())
                    ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                    ->toMediaCollection('documents');
                $attached++;
            } catch (FileCannotBeAdded $e) {
                // Mis. isi berkas tidak cocok dengan tipe yang diizinkan koleksi.
                $rejected++;
            }
        }

        if ($attached > 0) {
            $member->logDocumentActivity('Mengunggah '.$attached.' dokumen.');
        }

        if ($rejected > 0) {
            session()->flash('toast', ['type' => 'warning', 'message' => $rejected.' berkas dilewati karena tipe tidak didukung.']);
        }

        $this->reset('uploads');
    }

    /** Normalisasi nomor HP Indonesia ke "+62XXXXXXXXXX". Port dari MemberResource. */
    private function normalizePhone(?string $state): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $state);
        $digits = preg_replace('/^62/', '', (string) $digits);
        $digits = ltrim((string) $digits, '0');

        return $digits === '' ? null : '+62'.$digits;
    }

    /** Hilangkan awalan "+62" untuk ditampilkan di form edit. */
    private function localPhone(?string $state): ?string
    {
        if (blank($state)) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $state);
        $digits = preg_replace('/^62/', '', (string) $digits);

        return ltrim((string) $digits, '0') ?: '';
    }

    public function render(): View
    {
        $documents = $this->memberId
            ? Member::findOrFail($this->memberId)->getMedia('documents')
            : collect();

        return view('livewire.master.member.member-form', [
            'agencyOptions' => Agency::orderBy('agency_name')->pluck('agency_name', 'id'),
            'gradeOptions' => Grade::orderBy('code')->get(['id', 'code', 'name']),
            'heirRelationships' => Member::HEIR_RELATIONSHIPS,
            'canOverride' => $this->canOverrideMandatory(),
            'documents' => $documents,
        ])->layout('components.layouts.app', [
            'title' => $this->memberId ? 'Edit Anggota' : 'Tambah Anggota',
        ]);
    }
}
