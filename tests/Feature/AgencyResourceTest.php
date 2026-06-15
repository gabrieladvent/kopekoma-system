<?php

use App\Filament\Resources\AgencyResource;
use App\Filament\Resources\AgencyResource\Pages\CreateAgency;
use App\Filament\Resources\AgencyResource\Pages\EditAgency;
use App\Filament\Resources\AgencyResource\Pages\ListAgencies;
use App\Filament\Resources\AgencyResource\Pages\ViewAgency;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Agency;
use App\Models\User;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function livewire(string $component, array $params = [])
{
    return Livewire::test($component, $params);
}

beforeEach(function () {
    asSuperAdmin();
});

it('lists agencies on the index page', function () {
    $agencies = Agency::factory()->count(3)->create();

    livewire(ListAgencies::class)
        ->assertCanSeeTableRecords($agencies);
});

it('filters agencies by status', function () {
    $active = Agency::factory()->create(['status' => 'Aktif']);
    $inactive = Agency::factory()->nonActive()->create();

    livewire(ListAgencies::class)
        ->filterTable('status', 'Non-Aktif')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active]);
});

it('shows the member count column', function () {
    Agency::factory()->create();

    livewire(ListAgencies::class)
        ->assertCanRenderTableColumn('members_count');
});

it('creates an agency with a treasurer from users and normalized phone', function () {
    User::factory()->create(['name' => 'Budi']);

    livewire(CreateAgency::class)
        ->fillForm([
            'agency_code' => 'DISKES',
            'agency_name' => 'Dinas Kesehatan',
            'address' => 'Jl. Sehat No. 1',
            'payroll_treasurer' => 'Budi',
            'pic_phone_number' => '081234567890',
            'status' => 'Aktif',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $agency = Agency::where('agency_code', 'DISKES')->first();

    expect($agency)->not->toBeNull()
        ->and($agency->payroll_treasurer)->toBe('Budi')
        ->and($agency->pic_phone_number)->toBe('+6281234567890');
});

it('generates a unique agency code', function () {
    $code = AgencyResource::generateCode();

    expect($code)->toStartWith('OPD')
        ->and(strlen($code))->toBeLessThanOrEqual(10);

    Agency::factory()->create(['agency_code' => $code]);

    expect(AgencyResource::generateCode())->not->toBe($code);
});

it('normalizes various phone input formats on save', function (string $input, string $stored) {
    $agency = Agency::factory()->create();

    livewire(EditAgency::class, ['record' => $agency->getKey()])
        ->fillForm(['pic_phone_number' => $input])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($agency->refresh()->pic_phone_number)->toBe($stored);
})->with([
    'leading zero' => ['081234567890', '+6281234567890'],
    'with spaces' => ['0812 3456 7890', '+6281234567890'],
    'with 62 prefix' => ['6281234567890', '+6281234567890'],
]);

it('requires code, name, and status', function () {
    livewire(CreateAgency::class)
        ->fillForm([
            'agency_code' => null,
            'agency_name' => null,
            'status' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'agency_code' => 'required',
            'agency_name' => 'required',
            'status' => 'required',
        ]);
});

it('rejects duplicate agency code', function () {
    Agency::factory()->create(['agency_code' => 'DISKES']);

    livewire(CreateAgency::class)
        ->fillForm([
            'agency_code' => 'DISKES',
            'agency_name' => 'Dinas Lain',
            'status' => 'Aktif',
        ])
        ->call('create')
        ->assertHasFormErrors(['agency_code' => 'unique']);
});

it('redirects to the index after creating', function () {
    livewire(CreateAgency::class)
        ->fillForm([
            'agency_code' => 'DISDIK',
            'agency_name' => 'Dinas Pendidikan',
            'status' => 'Aktif',
        ])
        ->call('create')
        ->assertRedirect(AgencyResource::getUrl('index'));
});

it('shows a notification with a description after creating', function () {
    livewire(CreateAgency::class)
        ->fillForm([
            'agency_code' => 'DISPORA',
            'agency_name' => 'Dinas Pemuda',
            'status' => 'Aktif',
        ])
        ->call('create')
        ->assertNotified(
            Notification::make()
                ->success()
                ->title('Data berhasil dibuat')
                ->body('Data baru telah disimpan ke sistem.')
        );
});

it('registers the audit trail relation manager', function () {
    expect(AgencyResource::getRelations())
        ->toContain(AuditTrailRelationManager::class);
});

it('shows the audit trail of an agency in the relation manager', function () {
    $agency = Agency::factory()->create();

    $activity = Activity::where('subject_id', $agency->getKey())
        ->where('event', 'created')
        ->first();

    livewire(AuditTrailRelationManager::class, [
        'ownerRecord' => $agency,
        'pageClass' => ViewAgency::class,
    ])->assertCanSeeTableRecords([$activity]);
});

it('renders the view page with infolist', function () {
    $agency = Agency::factory()->create([
        'agency_name' => 'Dinas Kesehatan',
        'agency_code' => 'DISKES',
    ]);

    livewire(ViewAgency::class, ['record' => $agency->getKey()])
        ->assertOk()
        ->assertSee('Dinas Kesehatan')
        ->assertSee('DISKES');
});

it('writes an activity log when an agency is created', function () {
    $agency = Agency::factory()->create();

    expect(
        Activity::where('subject_type', Agency::class)
            ->where('subject_id', $agency->getKey())
            ->where('event', 'created')
            ->exists()
    )->toBeTrue();
});

it('updates an agency', function () {
    $agency = Agency::factory()->create(['agency_name' => 'Nama Lama']);

    livewire(EditAgency::class, ['record' => $agency->getKey()])
        ->fillForm(['agency_name' => 'Nama Baru'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($agency->refresh()->agency_name)->toBe('Nama Baru');
});

it('redirects to the detail view after saving an edit', function () {
    $agency = Agency::factory()->create();

    livewire(EditAgency::class, ['record' => $agency->getKey()])
        ->fillForm(['agency_name' => 'Nama Baru'])
        ->call('save')
        ->assertRedirect(AgencyResource::getUrl('view', ['record' => $agency->getKey()]));
});

it('deletes an agency', function () {
    $agency = Agency::factory()->create();

    livewire(EditAgency::class, ['record' => $agency->getKey()])
        ->callAction('delete');

    expect(Agency::find($agency->getKey()))->toBeNull();
});

it('allows keeping the same code on the same record', function () {
    $agency = Agency::factory()->create(['agency_code' => 'DISKES']);

    livewire(EditAgency::class, ['record' => $agency->getKey()])
        ->fillForm(['agency_code' => 'DISKES', 'agency_name' => 'Updated'])
        ->call('save')
        ->assertHasNoFormErrors();
});
