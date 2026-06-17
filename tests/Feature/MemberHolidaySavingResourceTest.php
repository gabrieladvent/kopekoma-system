<?php

use App\Filament\Resources\MemberHolidaySavingResource;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\CreateMemberHolidaySaving;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\EditMemberHolidaySaving;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\ListMemberHolidaySavings;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\ViewMemberHolidaySaving;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    asSuperAdmin();
});

it('lists holiday saving registrations', function () {
    $rows = MemberHolidaySaving::factory()->count(3)->create();

    Livewire::test(ListMemberHolidaySavings::class)
        ->assertCanSeeTableRecords($rows);
});

it('creates a holiday saving registration', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateMemberHolidaySaving::class)
        ->fillForm([
            'member_id' => $member->id,
            'period_year' => '2026-01-01',
            'monthly_amount' => '80000',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = MemberHolidaySaving::where('member_id', $member->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->period_year)->toBe(2026)
        ->and((float) $row->monthly_amount)->toBe(80000.0)
        ->and($row->is_active)->toBeTrue();
});

it('rejects a duplicate member + year registration', function () {
    $member = Member::factory()->create();
    MemberHolidaySaving::factory()->year(2026)->create(['member_id' => $member->id]);

    Livewire::test(CreateMemberHolidaySaving::class)
        ->fillForm([
            'member_id' => $member->id,
            'period_year' => '2026-01-01',
            'monthly_amount' => '90000',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['member_id']);
});

it('allows the same member in a different year', function () {
    $member = Member::factory()->create();
    MemberHolidaySaving::factory()->year(2026)->create(['member_id' => $member->id]);

    Livewire::test(CreateMemberHolidaySaving::class)
        ->fillForm([
            'member_id' => $member->id,
            'period_year' => '2027-01-01',
            'monthly_amount' => '90000',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MemberHolidaySaving::where('member_id', $member->id)->count())->toBe(2);
});

it('edits a registration', function () {
    $row = MemberHolidaySaving::factory()->create(['monthly_amount' => 50000]);

    Livewire::test(EditMemberHolidaySaving::class, ['record' => $row->getKey()])
        ->fillForm(['monthly_amount' => '120000'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $row->refresh()->monthly_amount)->toBe(120000.0);
});

it('renders the view page with infolist', function () {
    $row = MemberHolidaySaving::factory()->create(['period_year' => 2026]);

    Livewire::test(ViewMemberHolidaySaving::class, ['record' => $row->getKey()])
        ->assertOk()
        ->assertSee('2026');
});

it('writes an activity log when a registration is created', function () {
    $row = MemberHolidaySaving::factory()->create();

    expect(
        Activity::where('subject_type', MemberHolidaySaving::class)
            ->where('subject_id', $row->getKey())
            ->where('event', 'created')
            ->exists()
    )->toBeTrue();
});

it('registers the audit trail relation manager', function () {
    expect(MemberHolidaySavingResource::getRelations())
        ->toContain(AuditTrailRelationManager::class);
});
