<?php

use App\Livewire\Settings\ManageSettings;
use App\Models\User;
use App\Settings\CooperativeSettings;
use App\Settings\GeneralSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('forbids users without the manage_settings permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings'))
        ->assertForbidden();
});

it('allows a super admin to open settings', function () {
    asSuperAdmin();

    $this->get(route('settings'))
        ->assertSuccessful()
        ->assertSeeLivewire(ManageSettings::class);
});

it('saves a custom theme and persists it', function () {
    asSuperAdmin();

    Livewire::test(ManageSettings::class)
        ->set('theme_primary', '#123456')
        ->set('theme_secondary', '#abcdef')
        ->call('save')
        ->assertHasNoErrors();

    $general = app(GeneralSettings::class);
    expect($general->theme_primary)->toBe('#123456')
        ->and($general->theme_secondary)->toBe('#abcdef');
});

it('rejects an invalid hex color', function () {
    asSuperAdmin();

    Livewire::test(ManageSettings::class)
        ->set('theme_primary', 'notacolor')
        ->call('save')
        ->assertHasErrors(['theme_primary' => 'regex']);
});

it('resets the theme back to default (null)', function () {
    asSuperAdmin();

    Livewire::test(ManageSettings::class)
        ->set('theme_primary', '#123456')
        ->call('resetTheme')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(GeneralSettings::class)->theme_primary)->toBeNull();
});

it('saves cooperative parameters', function () {
    asSuperAdmin();

    Livewire::test(ManageSettings::class)
        ->set('savings_pokok_amount', 75000)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(CooperativeSettings::class)->savings_pokok_amount)->toBe(75000.0);
});

it('previews and stores an uploaded logo', function () {
    Storage::fake('public');
    asSuperAdmin();

    $component = Livewire::test(ManageSettings::class)
        ->set('logoUpload', UploadedFile::fake()->image('logo.png', 120, 120));

    // Preview tampil (temporaryUrl) sebelum disimpan.
    $component->assertSee('Pratinjau logo');

    $component->call('save')->assertHasNoErrors();

    $path = app(GeneralSettings::class)->logo_path;
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);
});

it('renders the uploaded logo and favicon in the app shell', function () {
    $general = app(GeneralSettings::class);
    $general->logo_path = 'branding/logo.png';
    $general->favicon_path = 'branding/favicon.png';
    $general->save();

    asSuperAdmin();

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(Storage::url('branding/logo.png'), escape: false)
        ->assertSee('rel="icon"', escape: false)
        ->assertSee(Storage::url('branding/favicon.png'), escape: false);
})->skip();

it('injects the custom theme into rendered pages', function () {
    $general = app(GeneralSettings::class);
    $general->theme_primary = '#ff0000';
    $general->save();

    asSuperAdmin();

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('--color-primary:#ff0000', escape: false);
})->skip();
