<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const RESOURCES = ['grade', 'agency', 'member'];

    private const BASE_PREFIXES = ['view', 'view_any', 'create', 'update'];

    private const ELEVATED_PREFIXES = [
        'delete', 'delete_any',
        'force_delete', 'force_delete_any',
        'restore', 'restore_any',
        'replicate', 'reorder',
    ];

    public function run(): void
    {
        Artisan::call('shield:generate', ['--all' => true, '--panel' => 'admin']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $pengurus = Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);

        $petugas = Role::firstOrCreate(['name' => 'petugas', 'guard_name' => 'web']);

        $superAdmin->givePermissionTo(Permission::all());

        $petugas->syncPermissions($this->permissionsFor(self::BASE_PREFIXES));

        $pengurus->syncPermissions($this->permissionsFor(
            array_merge(self::BASE_PREFIXES, self::ELEVATED_PREFIXES)
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private function permissionsFor(array $prefixes): array
    {
        $names = [];

        foreach (self::RESOURCES as $resource) {
            foreach ($prefixes as $prefix) {
                $names[] = "{$prefix}_{$resource}";
            }
        }

        return Permission::whereIn('name', $names)->pluck('name')->all();
    }
}
