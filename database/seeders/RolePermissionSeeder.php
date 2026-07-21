<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const RESOURCES = ['grade', 'agency', 'member', 'savings::deposit', 'member::holiday::saving', 'savings::withdrawal', 'shopping::transaction', 'loan', 'installment', 'loan::blacklist'];

    private const BASE_PREFIXES = ['view', 'view_any', 'create', 'update'];

    private const ELEVATED_PREFIXES = [
        'delete', 'delete_any',
        'force_delete', 'force_delete_any',
        'restore', 'restore_any',
        'replicate', 'reorder',
    ];

    private const CUSTOM_PETUGAS = [
        'reverse_savings::deposit',
        'reverse_savings::withdrawal',
        'reverse_shopping::transaction',
        'access_batch_salary_deduction',
        'reverse_installment',
        'access_laporan_setoran',
        'access_laporan_angsuran',
    ];

    private const CUSTOM_PENGURUS = [
        'reverse_savings::deposit',
        'reverse_savings::withdrawal',
        'reverse_shopping::transaction',
        'access_batch_salary_deduction',
        'reverse_installment',
        'approve_savings::withdrawal',
        'disburse_savings::withdrawal',
        'export_savings_recap',
        'reverse_loan',
        'manage_settings',
        'access_laporan_setoran',
        'access_laporan_angsuran',
        'export_laporan_setoran',
        'export_laporan_angsuran',
    ];

    private const CUSTOM_ADMIN_ONLY = [
        'copy_store_client_secret',
    ];

    public function run(): void
    {
        $this->ensureResourcePermissions();

        $this->ensureCustomPermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $pengurus = Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);

        $petugas = Role::firstOrCreate(['name' => 'petugas', 'guard_name' => 'web']);

        $superAdmin->givePermissionTo(Permission::all());

        $petugas->syncPermissions([
            ...$this->permissionsFor(self::BASE_PREFIXES),
            ...self::CUSTOM_PETUGAS,
        ]);

        $pengurus->syncPermissions([
            ...$this->permissionsFor(array_merge(self::BASE_PREFIXES, self::ELEVATED_PREFIXES)),
            ...self::CUSTOM_PENGURUS,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Bikin permission per-resource secara eksplisit.
     *
     * Dulu ini hasil `shield:generate --panel=admin`, tapi panel Filament sudah
     * tidak didaftarkan (lihat bootstrap/providers.php) sehingga command-nya
     * melempar NoDefaultPanelSetException dan seluruh seeder gagal. Nama
     * permission-nya tetap sama persis dengan yang digenerate Shield
     * ({prefix}_{resource}), jadi gate `can:` di routes/web.php tidak berubah.
     */
    private function ensureResourcePermissions(): void
    {
        $prefixes = array_merge(self::BASE_PREFIXES, self::ELEVATED_PREFIXES);

        foreach (self::RESOURCES as $resource) {
            foreach ($prefixes as $prefix) {
                Permission::firstOrCreate(['name' => "{$prefix}_{$resource}", 'guard_name' => 'web']);
            }
        }
    }

    private function ensureCustomPermissions(): void
    {
        foreach (array_unique([...self::CUSTOM_PETUGAS, ...self::CUSTOM_PENGURUS, ...self::CUSTOM_ADMIN_ONLY]) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
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
