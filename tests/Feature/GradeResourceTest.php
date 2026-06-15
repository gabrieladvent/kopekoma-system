<?php

use App\Filament\Resources\GradeResource;
use App\Filament\Resources\GradeResource\Pages\CreateGrade;
use App\Filament\Resources\GradeResource\Pages\ViewGrade;
use App\Models\Grade;
use Livewire\Livewire;

function livewireGrade(string $component, array $params = [])
{
    return Livewire::test($component, $params);
}

beforeEach(function () {
    asSuperAdmin();
});

it('creates a grade and redirects to the index', function () {
    livewireGrade(CreateGrade::class)
        ->fillForm([
            'code' => 'GOL-1',
            'name' => 'Golongan I',
            'mandatory_savings_amount' => 50000,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(GradeResource::getUrl('index'));

    $grade = Grade::where('code', 'GOL-1')->first();

    expect($grade)->not->toBeNull()
        ->and((float) $grade->mandatory_savings_amount)->toBe(50000.0);
});

it('renders the grade view page with infolist', function () {
    $grade = Grade::create([
        'code' => 'GOL-4',
        'name' => 'Golongan IV',
        'mandatory_savings_amount' => 150000,
        'is_active' => true,
    ]);

    livewireGrade(ViewGrade::class, ['record' => $grade->getKey()])
        ->assertOk()
        ->assertSee('Golongan IV')
        ->assertSee('GOL-4');
});
