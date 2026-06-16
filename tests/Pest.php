<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a super_admin user and authenticate as them. Shield's super_admin
 * role bypasses all resource policies via a Gate::before hook, so this keeps
 * Filament resource tests passing even after Shield policies are generated.
 */
function asSuperAdmin(): User
{
    $user = User::factory()->create();

    $role = Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
    ]);

    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

/**
 * Seed the Shield permissions + D4 roles (petugas, pengurus, super_admin),
 * then create and authenticate a user with the given role. Used by RBAC
 * matrix tests so the assigned role carries its real permission set.
 */
function asRole(string $role): User
{
    test()->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

function asPengurus(): User
{
    return asRole('pengurus');
}

function asPetugas(): User
{
    return asRole('petugas');
}
