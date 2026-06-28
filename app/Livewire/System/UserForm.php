<?php

namespace App\Livewire\System;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class UserForm extends Component
{
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @var array<int, string> Nama role yang dipilih. */
    public array $selectedRoles = [];

    public bool $is_active = true;

    public bool $email_verified = false;

    public function mount(?User $user = null): void
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        if ($user && $user->exists) {
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->selectedRoles = $user->roles->pluck('name')->all();
            $this->is_active = (bool) ($user->is_active ?? true);
            $this->email_verified = $user->email_verified_at !== null;
        }
    }

    /** Apakah form sedang menyunting akun pengguna yang sedang login. */
    public function isSelf(): bool
    {
        return $this->userId !== null && $this->userId === auth()->id();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->userId),
            ],
            'password' => [
                $this->userId ? 'nullable' : 'required',
                'confirmed',
                'min:8',
            ],
            'selectedRoles' => ['array'],
            'selectedRoles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'password' => 'password',
            'selectedRoles' => 'role',
        ];
    }

    public function save()
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        $validated = $this->validate();

        // Anti self-lockout: tidak boleh menonaktifkan akun sendiri.
        $isActive = $this->isSelf() ? true : $this->is_active;

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $isActive,
        ];

        if (filled($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        if ($this->userId) {
            $user = User::findOrFail($this->userId);
            $user->fill($data);
            $message = 'Pengguna diperbarui.';
        } else {
            $user = new User($data);
            $message = 'Pengguna ditambahkan.';
        }

        // email_verified_at bukan field mass-assignable → set eksplisit.
        $user->email_verified_at = $this->email_verified ? now() : null;
        $user->save();

        $user->syncRoles($this->selectedRoles);

        session()->flash('toast', ['type' => 'success', 'message' => $message]);

        return $this->redirectRoute('system.users', navigate: true);
    }

    public function render(): View
    {
        $roles = Role::orderBy('name')->pluck('name')->all();

        return view('livewire.system.user-form', [
            'roles' => $roles,
        ])->layout('components.layouts.app', [
            'title' => $this->userId ? 'Edit Pengguna' : 'Tambah Pengguna',
        ]);
    }
}
