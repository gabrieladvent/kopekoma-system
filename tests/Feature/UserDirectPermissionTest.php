<?php

use App\Livewire\System\UserForm;
use App\Models\User;
use App\Services\WithdrawalWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Izin per-pengguna — satu-satunya wewenang khusus yang bertahan lintas deploy.
 *
 * `RolePermissionSeeder` memakai `syncPermissions()` pada PERAN, jadi izin yang
 * ditambahkan ke sebuah peran lewat layar Peran & Izin akan dicabut diam-diam
 * oleh deploy berikutnya. Izin langsung ke pengguna tak tersentuh seeder itu.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('offers only permissions that no role owns', function () {
    asRole('super_admin');

    $offered = array_keys(Livewire::test(UserForm::class)->viewData('permissions'));

    expect($offered)->toContain(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION)
        // Izin yang sudah jadi bawaan peran tak ditawarkan — menambalnya lewat
        // pengguna satu per satu justru menghilangkan gunanya peran.
        ->and($offered)->not->toContain('reverse_installment')
        ->and($offered)->not->toContain('approve_savings::withdrawal');
});

it('grants a direct permission that the role does not carry', function () {
    asRole('super_admin');

    $target = User::factory()->create();
    $target->assignRole('pengurus');

    expect($target->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeFalse();

    Livewire::test(UserForm::class, ['user' => $target])
        ->set('selectedPermissions', [WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION])
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeTrue();
});

/** Inti alasannya: grant ini harus selamat dari seeder deploy berikutnya. */
it('survives a re-run of the role permission seeder', function () {
    asRole('super_admin');

    $target = User::factory()->create();
    $target->assignRole('pengurus');
    $target->givePermissionTo(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION);

    $this->seed(RolePermissionSeeder::class);

    expect($target->fresh()->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeTrue();
});

/** Pembandingnya: grant ke PERAN memang tercabut — itu yang kita hindari. */
it('shows why granting to a role instead would be wiped', function () {
    $pengurus = Role::findByName('pengurus');
    $pengurus->givePermissionTo(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION);

    $user = User::factory()->create();
    $user->assignRole('pengurus');

    expect($user->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeTrue();

    $this->seed(RolePermissionSeeder::class);

    expect($user->fresh()->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeFalse();
});

it('is closed to anyone but super_admin', function () {
    asPengurus();

    $this->get(route('system.users.create'))->assertForbidden();
});
