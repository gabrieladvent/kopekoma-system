<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
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
        // Reversal pembayaran angsuran = uniform Petugas+ (D5/D7).
        'reverse_installment',
        // Laporan: lihat/preview on-screen boleh Petugas+ (export terpisah, Pengurus-only).
        'access_laporan_setoran',
        'access_laporan_angsuran',
    ];

    private const CUSTOM_PENGURUS = [
        'reverse_savings::deposit',
        'reverse_savings::withdrawal',
        'reverse_shopping::transaction',
        'access_batch_salary_deduction',
        'reverse_installment',
        // Mata kedua sebelum uang keluar (D8-A/D10): hanya Pengurus+.
        'approve_savings::withdrawal',
        'disburse_savings::withdrawal',
        'export_savings_recap',
        // Koreksi salah-input pinjaman = reversal seluruh record → Pengurus+ saja (D3/2d).
        'reverse_loan',
        'manage_settings',
        // Laporan: akses view (juga di Petugas) + export PII finansial se-koperasi → Pengurus-only.
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
        Artisan::call('shield:generate', ['--all' => true, '--panel' => 'admin']);

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
