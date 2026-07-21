<?php

namespace App\Livewire\System;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Users extends Component
{
    use WithPagination;

    public string $search = '';

    /** Kredensial hasil reset password — ditampilkan sekali lewat modal. */
    public bool $showResetPassword = false;

    public ?string $resetPasswordValue = null;

    public ?string $resetPasswordUserName = null;

    public function mount(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function canManage(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $user = User::find($id);

        if (! $user) {
            return;
        }

        if ($user->is(auth()->user())) {
            $this->dispatch('toast', type: 'danger', message: 'Anda tidak dapat menghapus akun sendiri.');

            return;
        }

        $user->delete();
        $this->dispatch('toast', type: 'success', message: 'Pengguna dihapus.');
    }

    public function toggleActive(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $user = User::find($id);

        if (! $user) {
            return;
        }

        if ($user->is(auth()->user())) {
            $this->dispatch('toast', type: 'danger', message: 'Anda tidak dapat menonaktifkan akun sendiri.');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        // Dinonaktifkan → hapus sesinya supaya langsung ter-logout dari semua perangkat.
        if (! $user->is_active) {
            $user->invalidateSessions();
        }

        $this->dispatch('toast', type: 'success', message: $user->is_active
            ? 'Pengguna diaktifkan.'
            : 'Pengguna dinonaktifkan. Sesi loginnya telah diakhiri.');
    }

    /**
     * Reset paksa password pengguna: generate password acak baru, akhiri seluruh
     * sesi login pengguna tsb (force logout), lalu tampilkan password baru sekali
     * lewat modal agar admin bisa menyalin & menyerahkannya.
     */
    public function resetPassword(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $user = User::find($id);

        if (! $user) {
            return;
        }

        if ($user->is(auth()->user())) {
            $this->dispatch('toast', type: 'danger', message: 'Gunakan halaman Profil untuk mengganti password Anda sendiri.');

            return;
        }

        $plain = Str::password(14);
        $user->update(['password' => $plain]);
        $user->invalidateSessions(); // force logout semua perangkat

        $this->resetPasswordValue = $plain;
        $this->resetPasswordUserName = $user->name;
        $this->showResetPassword = true;
    }

    public function closeResetPassword(): void
    {
        $this->reset('showResetPassword', 'resetPasswordValue', 'resetPasswordUserName');
    }

    public function render(): View
    {
        $users = User::query()
            ->with('roles')
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.system.users', [
            'users' => $users,
            'currentUserId' => auth()->id(),
        ])->layout('components.layouts.app', ['title' => 'Pengguna']);
    }
}
