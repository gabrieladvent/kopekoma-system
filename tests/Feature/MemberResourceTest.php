<?php

use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\CreateMember;
use App\Filament\Resources\MemberResource\Pages\EditMember;
use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Filament\Resources\MemberResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\MemberResource\RelationManagers\LoansRelationManager;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Imports\MembersImport;
use App\Jobs\ImportMembersJob;
use App\Models\Agency;
use App\Models\Grade;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    asSuperAdmin();
});

function gradeGol1(): Grade
{
    return Grade::firstOrCreate(
        ['code' => 'GOL-1'],
        ['name' => 'Golongan I', 'mandatory_savings_amount' => 50000, 'is_active' => true],
    );
}

/**
 * @return array<string, mixed>
 */
function memberFormData(array $overrides = []): array
{
    $agency = Agency::factory()->create();
    $grade = gradeGol1();

    return array_merge([
        'full_name' => 'Budi Santoso',
        'nik' => '1234567890123456',
        'nip' => '199001012020121001',
        'birth_place' => 'Semarang',
        'birth_date' => '1990-01-01',
        'gender' => 'L',
        'agency_id' => $agency->getKey(),
        'grade_id' => $grade->id,
        'mandatory_savings_amount' => 50000,
        'position' => 'Staf',
        'employment_status' => 'ASN',
        'payroll_account_number' => '1234567890',
        'bank_name' => 'BRI',
        'phone_number' => '081234567890',
        'address' => 'Jl. Merdeka No. 1',
        'join_date' => '2026-01-01',
        'heir_name' => 'Siti',
        'heir_relationship' => 'Istri',
        'heir_phone_number' => '081298765432',
        'status' => 'Aktif',
    ], $overrides);
}

// ── Skeleton / CRUD (3a) ──────────────────────────────────────────────

it('lists members on the index page', function () {
    $members = Member::factory()->count(3)->create();

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords($members);
});

it('filters members by status', function () {
    $active = Member::factory()->create(['status' => 'Aktif']);
    $inactive = Member::factory()->nonActive()->create();

    Livewire::test(ListMembers::class)
        ->filterTable('status', 'Non-Aktif')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active]);
});

it('creates a member with normalized phone numbers', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    $member = Member::where('nik', '1234567890123456')->first();

    expect($member)->not->toBeNull()
        ->and($member->full_name)->toBe('Budi Santoso')
        ->and($member->phone_number)->toBe('+6281234567890')
        ->and($member->heir_phone_number)->toBe('+6281298765432');
});

it('requires the core identity fields', function () {
    Livewire::test(CreateMember::class)
        ->fillForm([
            'full_name' => null,
            'nik' => null,
            'agency_id' => null,
            'grade_id' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'full_name' => 'required',
            'nik' => 'required',
            'agency_id' => 'required',
            'grade_id' => 'required',
        ]);
});

// ── Member number auto-generation (3b / D2) ───────────────────────────

it('shows the next member number on the create form before saving', function () {
    $component = Livewire::test(CreateMember::class);

    expect($component->get('data.member_number'))->toMatch('/^KM-\d{4}-\d{4}$/');
});

it('auto-generates the member number on create', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    $member = Member::where('nik', '1234567890123456')->first();

    expect($member->member_number)->toMatch('/^KM-\d{4}-\d{4}$/');
});

it('generates sequential member numbers reset per year', function () {
    $year = (int) now()->format('Y');

    $first = Member::generateMemberNumber($year);
    Member::factory()->create(['member_number' => $first]);
    $second = Member::generateMemberNumber($year);

    expect($first)->toBe("KM-{$year}-0001")
        ->and($second)->toBe("KM-{$year}-0002")
        ->and(Member::generateMemberNumber(2099))->toBe('KM-2099-0001');
});

it('rejects a NIK that is not 16 digits', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['nik' => '123']))
        ->call('create')
        ->assertHasFormErrors(['nik']);
});

it('rejects a duplicate NIK', function () {
    Member::factory()->create(['nik' => '1111222233334444']);

    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['nik' => '1111222233334444']))
        ->call('create')
        ->assertHasFormErrors(['nik' => 'unique']);
});

// ── Conditional validation (3a2) ──────────────────────────────────────

it('requires NIP when the employment status is ASN', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['employment_status' => 'ASN', 'nip' => null]))
        ->call('create')
        ->assertHasFormErrors(['nip' => 'required']);
});

it('allows an empty NIP for Honorer', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['employment_status' => 'Honorer', 'nip' => null]))
        ->call('create')
        ->assertHasNoFormErrors();
});

it('requires exit date when status is Keluar', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['status' => 'Keluar', 'exit_date' => null]))
        ->call('create')
        ->assertHasFormErrors(['exit_date' => 'required']);
});

// ── Mandatory savings snapshot (3c / D1) ──────────────────────────────

it('auto-fills the mandatory savings snapshot from the grade on create', function () {
    $grade = gradeGol1();

    Livewire::test(CreateMember::class)
        ->fillForm(['grade_id' => $grade->id])
        ->assertFormSet(['mandatory_savings_amount' => 50000]);
});

it('persists the mandatory savings snapshot', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['mandatory_savings_amount' => 50000]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) Member::where('nik', '1234567890123456')->value('mandatory_savings_amount'))
        ->toBe(50000.0);
});

it('does not rewrite the snapshot when the grade changes on edit (D1)', function () {
    $member = Member::factory()->create(['mandatory_savings_amount' => 50000]);
    $otherGrade = Grade::firstOrCreate(
        ['code' => 'GOL-4'],
        ['name' => 'Golongan IV', 'mandatory_savings_amount' => 150000, 'is_active' => true],
    );

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->fillForm(['grade_id' => $otherGrade->id])
        ->assertFormSet(['mandatory_savings_amount' => 50000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $member->refresh()->mandatory_savings_amount)->toBe(50000.0);
});

it('restricts the mandatory savings override to Pengurus and above (D4)', function () {
    expect(MemberResource::canOverrideMandatorySavings())->toBeTrue(); // super_admin

    $this->actingAs(User::factory()->create()); // no role
    expect(MemberResource::canOverrideMandatorySavings())->toBeFalse();
});

// ── Edit / soft delete (3a / 3e) ──────────────────────────────────────

it('updates a member', function () {
    $member = Member::factory()->create(['full_name' => 'Nama Lama']);

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->fillForm(['full_name' => 'Nama Baru'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->refresh()->full_name)->toBe('Nama Baru');
});

it('redirects to the detail view after saving an edit', function () {
    $member = Member::factory()->create();

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->fillForm(['full_name' => 'Nama Baru'])
        ->call('save')
        ->assertRedirect(MemberResource::getUrl('view', ['record' => $member->getKey()]));
});

it('redirects to the index after creating', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData())
        ->call('create')
        ->assertRedirect(MemberResource::getUrl('index'));
});

it('shows a notification with a description after creating', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData())
        ->call('create')
        ->assertNotified(
            Notification::make()
                ->success()
                ->title('Data berhasil dibuat')
                ->body('Data baru telah disimpan ke sistem.')
        );
});

it('soft deletes a member', function () {
    $member = Member::factory()->create();

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->callAction('delete');

    expect(Member::find($member->getKey()))->toBeNull()
        ->and(Member::withTrashed()->find($member->getKey()))->not->toBeNull();
});

it('shows trashed members through the trashed filter', function () {
    $member = Member::factory()->create();
    $member->delete();

    Livewire::test(ListMembers::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$member]);
});

// ── View / infolist / audit (3a) ──────────────────────────────────────

it('renders the view page with infolist', function () {
    $member = Member::factory()->create(['full_name' => 'Budi Santoso']);

    Livewire::test(ViewMember::class, ['record' => $member->getKey()])
        ->assertOk()
        ->assertSee('Budi Santoso')
        ->assertSee($member->member_number);
});

it('registers the audit trail relation manager', function () {
    expect(MemberResource::getRelations())
        ->toContain(AuditTrailRelationManager::class);
});

it('writes an activity log when a member is created', function () {
    $member = Member::factory()->create();

    expect(
        Activity::where('subject_type', Member::class)
            ->where('subject_id', $member->getKey())
            ->where('event', 'created')
            ->exists()
    )->toBeTrue();
});

// ── Member card PDF (3f) ──────────────────────────────────────────────

it('streams the member card as a PDF download', function () {
    $member = Member::factory()->create();

    $response = MemberResource::printCard($member);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-disposition'))
        ->toContain('kartu-anggota-'.$member->member_number);
});

// ── Excel import (3g) ─────────────────────────────────────────────────

it('imports valid rows, skips invalid and intra-batch duplicates', function () {
    Agency::factory()->create(['agency_code' => 'DISKES']);
    gradeGol1();

    $header = 'nik,nama,nip,kode_opd,kode_golongan,tempat_lahir,tanggal_lahir,jenis_kelamin,jabatan,status_kepegawaian,no_rekening_gaji,nama_bank,alamat,no_hp,tanggal_bergabung,nama_ahli_waris,hubungan_ahli_waris,no_hp_ahli_waris,status,simpanan_wajib';
    $valid1 = '1234567890123456,Budi,199001012020121001,DISKES,GOL-1,Semarang,1990-01-01,L,Staf,ASN,111,BRI,Jl A,081234567890,2026-01-01,Siti,Istri,081298765432,Aktif,';
    $valid2 = '2234567890123456,Andi,,DISKES,GOL-1,Solo,1991-02-02,L,,Honorer,222,BNI,Jl B,081234567891,2026-01-02,Rina,Istri,081298765433,Aktif,';
    $invalid = '999,Cacat,,DISKES,GOL-1,Kudus,1992-03-03,L,,ASN,333,,Jl C,081234567892,2026-01-03,Tono,Anak,081298765434,Aktif,'; // NIK not 16 digits
    $dup = '1234567890123456,Kembar,,DISKES,GOL-1,Demak,1993-04-04,P,,ASN,444,,Jl D,081234567893,2026-01-04,Wati,Anak,081298765435,Aktif,'; // duplicate NIK of valid1

    $path = tempnam(sys_get_temp_dir(), 'members').'.csv';
    file_put_contents($path, implode("\n", [$header, $valid1, $valid2, $invalid, $dup]));

    $import = new MembersImport;
    Excel::import($import, $path);
    @unlink($path);

    expect(Member::count())->toBe(2)
        ->and(Member::where('nik', '1234567890123456')->exists())->toBeTrue()
        ->and(Member::where('nik', '2234567890123456')->exists())->toBeTrue();

    // member_number is system-generated, snapshot pulled from the grade.
    $imported = Member::where('nik', '1234567890123456')->first();
    expect($imported->member_number)->toMatch('/^KM-\d{4}-\d{4}$/')
        ->and((float) $imported->mandatory_savings_amount)->toBe(50000.0)
        ->and($imported->phone_number)->toBe('+6281234567890');
});

// ── Media documents (UUID morph key) ─────────────────────────────────

it('attaches a document to a member with a uuid model id', function () {
    Storage::fake('public');
    $member = Member::factory()->create();

    $member->addMedia(UploadedFile::fake()->image('ktp.jpg', 10, 10))
        ->toMediaCollection('documents');

    $media = $member->getMedia('documents');

    expect($media)->toHaveCount(1)
        ->and($media->first()->model_id)->toBe($member->getKey());
});

it('records an activity log when a member document changes', function () {
    $member = Member::factory()->create();

    $member->logDocumentActivity('Mengunggah dokumen: ktp.pdf');

    expect(
        Activity::where('subject_type', Member::class)
            ->where('subject_id', $member->getKey())
            ->where('event', 'updated')
            ->where('description', 'Mengunggah dokumen: ktp.pdf')
            ->exists()
    )->toBeTrue();
});

it('lists member documents in the documents relation manager', function () {
    Storage::fake('public');
    $member = Member::factory()->create();
    $member->addMedia(UploadedFile::fake()->image('ktp.jpg', 10, 10))->toMediaCollection('documents');

    Livewire::test(DocumentsRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => ViewMember::class,
    ])->assertCanSeeTableRecords([$member->getFirstMedia('documents')]);
});

it('lists all member loans including Dibatalkan in the loan history relation manager', function () {
    $member = Member::factory()->create();
    $active = Loan::factory()->create(['member_id' => $member->id, 'status' => 'Cair']);
    $cancelled = Loan::factory()->create(['member_id' => $member->id, 'status' => 'Dibatalkan']);
    $otherMemberLoan = Loan::factory()->create();

    Livewire::test(LoansRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => ViewMember::class,
    ])
        ->assertCanSeeTableRecords([$active, $cancelled])
        ->assertCanNotSeeTableRecords([$otherMemberLoan]);
});

// ── Heir relationship enum + validation messages ──────────────────────

it('stores the heir relationship from the enum option set', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['heir_relationship' => 'Anak']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Member::where('nik', '1234567890123456')->value('heir_relationship'))->toBe('Anak');
});

it('rejects a heir relationship outside the option set', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(memberFormData(['heir_relationship' => 'Tetangga']))
        ->call('create')
        ->assertHasFormErrors(['heir_relationship']);
});

it('produces Indonesian validation messages', function () {
    app()->setLocale('id');

    $messages = Validator::make(
        ['nik' => '', 'nip' => ''],
        ['nik' => 'required', 'nip' => 'required'],
    )->messages();

    expect($messages->first('nik'))->toContain('wajib diisi')
        ->and($messages->first('nip'))->toBe('NIP wajib diisi untuk pegawai ASN.');
});

// ── Import template + queued import (3g) ──────────────────────────────

it('provides a downloadable import template', function () {
    Excel::fake();

    Livewire::test(ListMembers::class)
        ->callAction('downloadTemplate');

    Excel::assertDownloaded('template-import-anggota.xlsx');
});

it('imports members in the background job and notifies the uploader', function () {
    Storage::fake('local');
    Agency::factory()->create(['agency_code' => 'DISKES']);
    gradeGol1();
    $user = User::factory()->create();

    $header = 'nik,nama,nip,kode_opd,kode_golongan,tempat_lahir,tanggal_lahir,jenis_kelamin,jabatan,status_kepegawaian,no_rekening_gaji,nama_bank,alamat,no_hp,tanggal_bergabung,nama_ahli_waris,hubungan_ahli_waris,no_hp_ahli_waris,status,simpanan_wajib';
    $row = '1234567890123456,Budi,199001012020121001,DISKES,GOL-1,Semarang,1990-01-01,L,Staf,ASN,111,BRI,Jl A,081234567890,2026-01-01,Siti,Istri,081298765432,Aktif,';
    Storage::disk('local')->put('imports/members/test.csv', implode("\n", [$header, $row]));

    (new ImportMembersJob('imports/members/test.csv', 'local', $user->id))->handle();

    expect(Member::where('nik', '1234567890123456')->exists())->toBeTrue()
        ->and($user->notifications()->count())->toBe(1)
        ->and(Storage::disk('local')->exists('imports/members/test.csv'))->toBeFalse();
});
