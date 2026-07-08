<?php

namespace App\Livewire\System;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleForm extends Component
{
    public ?int $roleId = null;

    public string $name = '';

    public string $guard_name = 'web';

    /** @var array<int, string> Nama permission yang dipilih. */
    public array $selected = [];

    public bool $isSuperAdmin = false;

    /**
     * Prefix permission resource — urut dari yang paling spesifik supaya
     * "view_any" tertangkap sebelum "view", "delete_any" sebelum "delete", dst.
     */
    private const PREFIXES = [
        'view_any' => 'Lihat Daftar',
        'view' => 'Lihat',
        'create' => 'Tambah',
        'update' => 'Ubah',
        'restore_any' => 'Pulihkan Massal',
        'restore' => 'Pulihkan',
        'replicate' => 'Duplikat',
        'reorder' => 'Urutkan',
        'force_delete_any' => 'Hapus Permanen Massal',
        'force_delete' => 'Hapus Permanen',
        'delete_any' => 'Hapus Massal',
        'delete' => 'Hapus',
    ];

    private const RESOURCE_LABELS = [
        'grade' => 'Golongan',
        'agency' => 'OPD / Instansi',
        'member' => 'Anggota',
        'savings::deposit' => 'Setoran Simpanan',
        'member::holiday::saving' => 'Simpanan Hari Raya',
        'savings::withdrawal' => 'Penarikan Simpanan',
        'shopping::transaction' => 'Transaksi Belanja',
    ];

    private const CUSTOM_LABELS = [
        'reverse_savings::deposit' => 'Reversal Setoran Simpanan',
        'reverse_savings::withdrawal' => 'Reversal Penarikan Simpanan',
        'reverse_shopping::transaction' => 'Reversal Transaksi Belanja',
        'access_batch_salary_deduction' => 'Akses Batch Potong Gaji',
        'approve_savings::withdrawal' => 'Setujui Penarikan (ACC)',
        'disburse_savings::withdrawal' => 'Cairkan Penarikan',
        'export_savings_recap' => 'Ekspor Rekap Simpanan',
        'access_laporan_setoran' => 'Akses Laporan Setoran Simpanan',
        'access_laporan_angsuran' => 'Akses Laporan Angsuran Pinjaman',
        'export_laporan_setoran' => 'Ekspor Laporan Setoran Simpanan',
        'export_laporan_angsuran' => 'Ekspor Laporan Angsuran Pinjaman',
        'manage_settings' => 'Kelola Pengaturan',
        'copy_store_client_secret' => 'Salin Secret Store Client',
    ];

    public function mount(?Role $role = null): void
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        if ($role && $role->exists) {
            $this->roleId = $role->id;
            $this->name = $role->name;
            $this->guard_name = $role->guard_name;
            $this->isSuperAdmin = $role->name === 'super_admin';
            $this->selected = $this->isSuperAdmin
                ? Permission::pluck('name')->all()
                : $role->permissions->pluck('name')->all();
        }
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where('guard_name', $this->guard_name)->ignore($this->roleId),
            ],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['name' => 'nama peran'];
    }

    public function selectAllPermissions(): void
    {
        if ($this->isSuperAdmin) {
            return;
        }

        $this->selected = Permission::pluck('name')->all();
    }

    public function clearPermissions(): void
    {
        if ($this->isSuperAdmin) {
            return;
        }

        $this->selected = [];
    }

    /** Toggle satu grup resource: kalau semua sudah tercentang → lepas, jika tidak → centang semua. */
    public function toggleGroup(array $perms): void
    {
        if ($this->isSuperAdmin) {
            return;
        }

        $allChecked = empty(array_diff($perms, $this->selected));

        $this->selected = $allChecked
            ? array_values(array_diff($this->selected, $perms))
            : array_values(array_unique([...$this->selected, ...$perms]));
    }

    public function save()
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        $validated = $this->validate();

        if ($this->roleId) {
            $role = Role::findOrFail($this->roleId);
            $role->update(['name' => $validated['name']]);
            $message = 'Peran diperbarui.';
        } else {
            $role = Role::create(['name' => $validated['name'], 'guard_name' => $this->guard_name]);
            $message = 'Peran ditambahkan.';
        }

        // Super admin selalu mendapat semua izin via Gate::before — jangan disinkron.
        if (! $this->isSuperAdmin) {
            $role->syncPermissions($this->selected);
        }

        session()->flash('toast', ['type' => 'success', 'message' => $message]);

        return $this->redirectRoute('system.roles', navigate: true);
    }

    /**
     * Susun permission jadi grup resource + grup khusus.
     *
     * @return array{groups: array<int, array{key: string, label: string, perms: array<string, string>, names: array<int, string>}>, custom: array<string, string>}
     */
    private function buildPermissionMatrix(): array
    {
        $all = Permission::orderBy('name')->pluck('name');

        $grouped = [];   // resource => [perm => prefixLabel]
        $custom = [];    // permName => label

        foreach ($all as $perm) {
            $matched = false;

            foreach (self::PREFIXES as $prefix => $prefixLabel) {
                if (str_starts_with($perm, $prefix.'_')) {
                    $resource = substr($perm, strlen($prefix) + 1);
                    $grouped[$resource][$perm] = $prefixLabel;
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $custom[$perm] = self::CUSTOM_LABELS[$perm] ?? ucfirst(str_replace(['_', '::'], [' ', ' '], $perm));
            }
        }

        $groups = [];
        foreach ($grouped as $resource => $perms) {
            $groups[] = [
                'key' => $resource,
                'label' => self::RESOURCE_LABELS[$resource] ?? ucfirst(str_replace('::', ' ', $resource)),
                'perms' => $perms,
                'names' => array_keys($perms),
            ];
        }

        return ['groups' => $groups, 'custom' => $custom];
    }

    public function render(): View
    {
        $matrix = $this->buildPermissionMatrix();

        return view('livewire.system.role-form', [
            'groups' => $matrix['groups'],
            'custom' => $matrix['custom'],
            'totalPermissions' => Permission::count(),
        ])->layout('components.layouts.app', [
            'title' => $this->roleId ? 'Edit Peran' : 'Tambah Peran',
        ]);
    }
}
