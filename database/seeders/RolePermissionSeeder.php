<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Resources covered by the D4 access matrix (Master Data Koperasi ADR).
     */
    private const RESOURCES = ['grade', 'agency', 'member'];

    /**
     * Permission prefixes every role may use (read + write, no destructive).
     */
    private const BASE_PREFIXES = ['view', 'view_any', 'create', 'update'];

    /**
     * Destructive / restore prefixes reserved for Pengurus and above (D4).
     */
    private const ELEVATED_PREFIXES = [
        'delete', 'delete_any',
        'force_delete', 'force_delete_any',
        'restore', 'restore_any',
        'replicate', 'reorder',
    ];

    public function run(): void
    {
        // Make sure Shield-generated permissions exist before we assign them.
        // Idempotent: safe to re-run on an already-generated app.
        Artisan::call('shield:generate', ['--all' => true, '--panel' => 'admin']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $pengurus = Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);
        $petugas = Role::firstOrCreate(['name' => 'petugas', 'guard_name' => 'web']);

        // super_admin bypasses every gate via Shield (config super_admin.define_via_gate),
        // but we still grant all permissions so direct ->can() checks behave too.
        $superAdmin->givePermissionTo(Permission::all());

        $petugas->syncPermissions($this->permissionsFor(self::BASE_PREFIXES));
        $pengurus->syncPermissions($this->permissionsFor(
            array_merge(self::BASE_PREFIXES, self::ELEVATED_PREFIXES)
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Resolve the existing permission names for the given prefixes across all
     * D4 resources. Only permissions that actually exist are returned, so a
     * resource missing a given prefix simply contributes nothing.
     *
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
