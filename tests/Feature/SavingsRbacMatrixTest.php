<?php

use Spatie\Permission\Models\Permission;

/**
 * Audit matriks RBAC Modul Simpanan (item 6, D7). Gating berbasis **permission
 * Shield** (bukan nama role hardcoded) sehingga reconfigurable.
 *
 * | Ability                              | Petugas | Pengurus | Super Admin |
 * | view/view_any/create/update (4 res)  |   ✅    |    ✅    |     ✅      |
 * | reverse_* (deposit/withdrawal/shop)  |   ✅    |    ✅    |     ✅      |
 * | access_batch_salary_deduction        |   ✅    |    ✅    |     ✅      |
 * | approve/disburse_savings::withdrawal |   ❌    |    ✅    |     ✅      |
 * | export_savings_recap                 |   ❌    |    ✅    |     ✅      |
 * | delete_* (elevated)                  |   ❌    |    ✅    |     ✅      |
 */
$savingsResources = ['savings::deposit', 'savings::withdrawal', 'member::holiday::saving', 'shopping::transaction'];

$baseAbilities = ['view_any', 'view', 'create', 'update'];

$reverseAbilities = ['reverse_savings::deposit', 'reverse_savings::withdrawal', 'reverse_shopping::transaction'];

$pengurusOnly = ['approve_savings::withdrawal', 'disburse_savings::withdrawal', 'export_savings_recap'];

it('grants base CRUD on every savings resource to petugas', function () use ($savingsResources, $baseAbilities) {
    $petugas = asPetugas();

    foreach ($savingsResources as $res) {
        foreach ($baseAbilities as $ability) {
            expect($petugas->can("{$ability}_{$res}"))->toBeTrue("petugas should have {$ability}_{$res}");
        }
    }
});

it('grants reversal + batch to petugas but withholds approve/disburse/export (D7)', function () use ($reverseAbilities, $pengurusOnly) {
    $petugas = asPetugas();

    foreach ($reverseAbilities as $ability) {
        expect($petugas->can($ability))->toBeTrue("petugas should have {$ability}");
    }
    expect($petugas->can('access_batch_salary_deduction'))->toBeTrue();

    foreach ($pengurusOnly as $ability) {
        expect($petugas->can($ability))->toBeFalse("petugas must NOT have {$ability}");
    }
});

it('forbids petugas from deleting savings records', function () use ($savingsResources) {
    $petugas = asPetugas();

    foreach ($savingsResources as $res) {
        expect($petugas->can("delete_{$res}"))->toBeFalse("petugas must NOT delete {$res}");
    }
});

it('grants approve/disburse/export and delete to pengurus (D7)', function () use ($savingsResources, $pengurusOnly) {
    $pengurus = asPengurus();

    foreach ($pengurusOnly as $ability) {
        expect($pengurus->can($ability))->toBeTrue("pengurus should have {$ability}");
    }
    foreach ($savingsResources as $res) {
        expect($pengurus->can("delete_{$res}"))->toBeTrue("pengurus should delete {$res}");
    }
    // Pengurus juga punya reversal + batch.
    expect($pengurus->can('reverse_savings::withdrawal'))->toBeTrue()
        ->and($pengurus->can('access_batch_salary_deduction'))->toBeTrue();
});

it('lets super admin do everything via Gate bypass', function () use ($pengurusOnly) {
    asSuperAdmin();

    foreach ([...$pengurusOnly, 'reverse_savings::deposit', 'delete_savings::withdrawal', 'access_batch_salary_deduction'] as $ability) {
        expect(auth()->user()->can($ability))->toBeTrue("super admin should have {$ability}");
    }
});

it('seeds every custom savings ability as a real permission', function () {
    asPetugas(); // memicu RolePermissionSeeder

    $custom = [
        'reverse_savings::deposit', 'reverse_savings::withdrawal', 'reverse_shopping::transaction',
        'approve_savings::withdrawal', 'disburse_savings::withdrawal',
        'access_batch_salary_deduction', 'export_savings_recap',
    ];

    foreach ($custom as $name) {
        expect(Permission::where('name', $name)->exists())
            ->toBeTrue("permission {$name} harus ada");
    }
});
