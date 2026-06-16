<?php

use App\Filament\Resources\MemberResource;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

/**
 * D4 access matrix (Master Data Koperasi ADR):
 *
 * | Permission        | Petugas | Pengurus | Super Admin |
 * | view / viewAny    |   ✅    |    ✅    |     ✅      |
 * | create / update   |   ✅    |    ✅    |     ✅      |
 * | delete / restore  |   ❌    |    ✅    |     ✅      |
 * | export (PDF/Excel)|   ❌    |    ✅    |     ✅      |
 * | import Excel       |   ❌    |    ✅    |     ✅      |
 */
$resources = ['grade', 'agency', 'member'];

it('seeds the three D4 roles', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(Role::pluck('name')->all())
        ->toContain('petugas', 'pengurus', 'super_admin');
});

it('lets petugas view, create and update every master-data resource', function () use ($resources) {
    $petugas = asPetugas();

    foreach ($resources as $resource) {
        expect($petugas->can("view_any_{$resource}"))->toBeTrue();
        expect($petugas->can("view_{$resource}"))->toBeTrue();
        expect($petugas->can("create_{$resource}"))->toBeTrue();
        expect($petugas->can("update_{$resource}"))->toBeTrue();
    }
});

it('forbids petugas from deleting or restoring any master-data resource', function () use ($resources) {
    $petugas = asPetugas();

    foreach ($resources as $resource) {
        expect($petugas->can("delete_{$resource}"))->toBeFalse();
        expect($petugas->can("delete_any_{$resource}"))->toBeFalse();
        expect($petugas->can("force_delete_{$resource}"))->toBeFalse();
        expect($petugas->can("restore_{$resource}"))->toBeFalse();
    }
});

it('forbids petugas from importing, exporting and overriding mandatory savings', function () {
    asPetugas();

    expect(MemberResource::canImportMembers())->toBeFalse();
    expect(MemberResource::canExportMembers())->toBeFalse();
    expect(MemberResource::canOverrideMandatorySavings())->toBeFalse();
});

it('lets pengurus delete and restore every master-data resource', function () use ($resources) {
    $pengurus = asPengurus();

    foreach ($resources as $resource) {
        expect($pengurus->can("delete_{$resource}"))->toBeTrue();
        expect($pengurus->can("delete_any_{$resource}"))->toBeTrue();
        expect($pengurus->can("force_delete_{$resource}"))->toBeTrue();
        expect($pengurus->can("restore_{$resource}"))->toBeTrue();
    }
});

it('lets pengurus import, export and override mandatory savings', function () {
    asPengurus();

    expect(MemberResource::canImportMembers())->toBeTrue();
    expect(MemberResource::canExportMembers())->toBeTrue();
    expect(MemberResource::canOverrideMandatorySavings())->toBeTrue();
});

it('lets super_admin do everything via gate bypass', function () use ($resources) {
    $superAdmin = asRole('super_admin');

    foreach ($resources as $resource) {
        expect($superAdmin->can("delete_{$resource}"))->toBeTrue();
        expect($superAdmin->can("force_delete_any_{$resource}"))->toBeTrue();
    }

    expect(MemberResource::canImportMembers())->toBeTrue();
    expect(MemberResource::canExportMembers())->toBeTrue();
    expect(MemberResource::canOverrideMandatorySavings())->toBeTrue();
});
