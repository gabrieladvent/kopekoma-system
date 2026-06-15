<?php

use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\Agency;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function livewireActivity(string $component, array $params = [])
{
    return Livewire::test($component, $params);
}

beforeEach(function () {
    asSuperAdmin();
});

it('lists activity log entries', function () {
    Agency::factory()->create();

    $activity = Activity::query()->latest('id')->first();

    livewireActivity(ListActivities::class)
        ->assertCanSeeTableRecords([$activity]);
});

it('filters activity by event', function () {
    Agency::factory()->create();

    livewireActivity(ListActivities::class)
        ->filterTable('event', 'created')
        ->assertCanSeeTableRecords(Activity::where('event', 'created')->get())
        ->filterTable('event', 'deleted')
        ->assertCanNotSeeTableRecords(Activity::where('event', 'created')->get());
});

it('renders the activity view page', function () {
    Agency::factory()->create(['agency_name' => 'Dinas Tes']);

    $activity = Activity::query()->latest('id')->first();

    livewireActivity(ViewActivity::class, ['record' => $activity->getKey()])
        ->assertOk();
});

it('does not allow creating activity entries', function () {
    expect(ActivityResource::canCreate())->toBeFalse();
});
