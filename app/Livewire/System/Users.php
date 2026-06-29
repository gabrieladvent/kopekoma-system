<?php

namespace App\Livewire\System;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Users extends Component
{
    use WithPagination;

    public string $search = '';

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

        $this->dispatch('toast', type: 'success', message: $user->is_active
            ? 'Pengguna diaktifkan.'
            : 'Pengguna dinonaktifkan.');
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
