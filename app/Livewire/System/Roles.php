<?php

namespace App\Livewire\System;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class Roles extends Component
{
    public function mount(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function canManage(): bool
    {
        // IZIN, bukan peran. Peran yang dipatok di kode tak bisa diberikan
        // koperasi kepada siapa pun tanpa mengubah kode; bawaannya tetap
        // super_admin saja, yang berubah hanya: sekarang bisa diberikan.
        return auth()->user()?->can('access_system_roles') ?? false;
    }

    public function delete(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $role = Role::find($id);

        if (! $role) {
            return;
        }

        if ($role->name === 'super_admin') {
            $this->dispatch('toast', type: 'danger', message: 'Peran super_admin tidak dapat dihapus.');

            return;
        }

        if ($role->users()->count() > 0) {
            $this->dispatch('toast', type: 'danger', message: 'Tidak bisa dihapus: masih ada pengguna dengan peran ini.');

            return;
        }

        $role->delete();
        $this->dispatch('toast', type: 'success', message: 'Peran dihapus.');
    }

    public function render(): View
    {
        $roles = Role::query()
            ->withCount('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions_count' => $role->permissions_count,
                'users_count' => $role->users()->count(),
                'is_super_admin' => $role->name === 'super_admin',
            ]);

        return view('livewire.system.roles', [
            'roles' => $roles,
        ])->layout('components.layouts.app', ['title' => 'Peran & Izin']);
    }
}
