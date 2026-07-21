<?php

use App\Filament\Resources\MemberHolidaySavingResource;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\CreateMemberHolidaySaving;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\EditMemberHolidaySaving;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\ListMemberHolidaySavings;
use App\Filament\Resources\MemberHolidaySavingResource\Pages\ViewMemberHolidaySaving;
use App\Filament\Resources\MemberHolidaySavingResource\RelationManagers\DepositsRelationManager;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Services\SavingsBalanceService;
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

it('creates a holiday saving registration and derives period_year from end_date', function () {
    $member = Member::factory()->create();

    // Rentang melintasi tahun: tahun program = tahun end_date (pembagian), bukan start.
    Livewire::test(CreateMemberHolidaySaving::class)
        ->fillForm([
            'member_id' => $member->id,
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
            'monthly_amount' => '80000',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = MemberHolidaySaving::where('member_id', $member->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->period_year)->toBe(2027)
        ->and((float) $row->monthly_amount)->toBe(80000.0)
        ->and($row->is_active)->toBeTrue();
});

it('rejects an end_date before the start_date', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateMemberHolidaySaving::class)
        ->fillForm([
            'member_id' => $member->id,
            'start_date' => '2026-12-31',
            'end_date' => '2026-01-01',
            'monthly_amount' => '80000',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['end_date']);
});

it('rejects a duplicate member + program year registration', function () {
    $member = Member::factory()->create();
    MemberHolidaySaving::factory()->year(2026)->create(['member_id' => $member->id]);

    Livewire::test(CreateMemberHolidaySaving::class)
        ->fillForm([
            'member_id' => $member->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'monthly_amount' => '90000',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['member_id']);
});

it('allows the same member in a different program year', function () {
    $member = Member::factory()->create();
    MemberHolidaySaving::factory()->year(2026)->create(['member_id' => $member->id]);

    Livewire::test(CreateMemberHolidaySaving::class)
        ->fillForm([
            'member_id' => $member->id,
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
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

it('shows the accumulated holiday balance for the program year on the view page', function () {
    $member = Member::factory()->create();
    $registration = MemberHolidaySaving::factory()->range('2026-01-01', '2026-12-31')->create([
        'member_id' => $member->id,
    ]);

    // 2 setoran 2026 (200rb), 1 di-reversal (−100rb), 1 setoran tahun lain (diabaikan).
    SavingsDeposit::factory()->holiday(2026)->create(['member_id' => $member->id, 'amount' => 100000]);
    SavingsDeposit::factory()->holiday(2026)->create(['member_id' => $member->id, 'amount' => 100000, 'is_reversal' => true]);
    SavingsDeposit::factory()->holiday(2027)->create(['member_id' => $member->id, 'amount' => 100000]);

    expect(app(SavingsBalanceService::class)->holidayBalance($member, 2026))->toBe('0.00');

    Livewire::test(ViewMemberHolidaySaving::class, ['record' => $registration->getKey()])
        ->assertOk()
        ->assertSee('Saldo Terkumpul');
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

it('registers the audit trail and deposits relation managers', function () {
    expect(MemberHolidaySavingResource::getRelations())
        ->toContain(AuditTrailRelationManager::class)
        ->toContain(DepositsRelationManager::class);
});

it('shows only this program-year hari_raya deposits in the relation manager', function () {
    $member = Member::factory()->create();
    $registration = MemberHolidaySaving::factory()->range('2026-01-01', '2026-12-31')->create([
        'member_id' => $member->id,
    ]);

    // Setoran hari_raya yang di-tag ke tahun program (2026) → tampil.
    $inProgram = SavingsDeposit::factory()->holiday(2026)->create(['member_id' => $member->id]);

    // Tahun program lain (2027) → tidak tampil.
    $otherYear = SavingsDeposit::factory()->holiday(2027)->create(['member_id' => $member->id]);
    // Jenis lain anggota sama → tidak tampil.
    $otherType = SavingsDeposit::factory()->type('sukarela')->create(['member_id' => $member->id]);
    // Setoran hari_raya anggota lain → tidak tampil.
    $otherMember = SavingsDeposit::factory()->holiday(2026)->create();

    Livewire::test(DepositsRelationManager::class, [
        'ownerRecord' => $registration,
        'pageClass' => ViewMemberHolidaySaving::class,
    ])
        ->assertCanSeeTableRecords([$inProgram])
        ->assertCanNotSeeTableRecords([$otherYear, $otherType, $otherMember]);
});
