<?php

namespace App\Livewire\System;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
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

    /**
     * Izin yang diberikan LANGSUNG ke pengguna ini, di luar perannya.
     *
     * Bukan duplikasi layar Peran. `RolePermissionSeeder` memakai
     * `syncPermissions()` pada PERAN, jadi izin yang ditambahkan ke sebuah peran
     * lewat layar Peran & Izin akan **dicabut diam-diam** oleh deploy
     * berikutnya. Izin per-pengguna tak tersentuh seeder itu — jadi inilah
     * satu-satunya tempat memberikan wewenang khusus yang bertahan lintas rilis
     * tanpa mengubah kode.
     *
     * Contohnya `bypass_time_deposit_schedule`: sengaja bukan milik peran mana
     * pun, tapi kadang perlu dipegang satu orang tertentu.
     *
     * @var array<int, string>
     */
    public array $selectedPermissions = [];

    public bool $is_active = true;

    public bool $email_verified = false;

    /** Kata sandi acak yang digenerate saat membuat pengguna baru (ditampilkan sekali). */
    public ?string $generatedPassword = null;

    /** Kontrol tampil modal "salin kata sandi" setelah create berhasil. */
    public bool $showCredentials = false;

    public function mount(?User $user = null): void
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        if ($user && $user->exists) {
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->selectedRoles = $user->roles->pluck('name')->all();
            // `permissions` = izin langsung saja, tidak termasuk yang diwarisi
            // dari peran — persis yang boleh diubah di layar ini.
            $this->selectedPermissions = $user->permissions->pluck('name')->all();
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
            // Saat create, kata sandi digenerate otomatis (tidak diinput user).
            // Saat edit, opsional: kosongkan bila tidak ingin mengubah.
            'password' => $this->userId
                ? ['nullable', 'confirmed', 'min:8']
                : ['nullable'],
            'selectedRoles' => ['array'],
            'selectedRoles.*' => ['string', Rule::exists('roles', 'name')],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', Rule::exists('permissions', 'name')],
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

        if ($this->userId) {
            // Edit: ganti kata sandi hanya bila diisi.
            if (filled($validated['password'] ?? null)) {
                $data['password'] = $validated['password'];
            }

            $user = User::findOrFail($this->userId);
            $user->fill($data);
            $user->email_verified_at = $this->email_verified ? now() : null;
            $user->save();
            $user->syncRoles($this->selectedRoles);
            $user->syncPermissions($this->selectedPermissions);

            // Dinonaktifkan lewat form edit → akhiri sesinya (langsung ter-logout).
            if (! $isActive) {
                $user->invalidateSessions();
            }

            session()->flash('toast', ['type' => 'success', 'message' => 'Pengguna diperbarui.']);

            return $this->redirectRoute('system.users', navigate: true);
        }

        // Create: generate kata sandi acak yang kuat, lalu tampilkan sekali agar
        // admin bisa menyalin & memberikannya ke pengguna.
        $plainPassword = Str::password(14);
        $data['password'] = $plainPassword;

        $user = new User($data);
        $user->email_verified_at = $this->email_verified ? now() : null;
        $user->save();
        $user->syncRoles($this->selectedRoles);
        $user->syncPermissions($this->selectedPermissions);

        $this->generatedPassword = $plainPassword;
        $this->showCredentials = true;

        // Jangan redirect: modal kredensial harus tampil dulu (lihat finishCreate()).
        return null;
    }

    /** Tutup modal kredensial & lanjut ke daftar pengguna. */
    public function finishCreate()
    {
        session()->flash('toast', ['type' => 'success', 'message' => 'Pengguna ditambahkan.']);

        return $this->redirectRoute('system.users', navigate: true);
    }

    public function render(): View
    {
        $roles = Role::orderBy('name')->pluck('name')->all();

        // Hanya izin yang BUKAN bawaan peran mana pun yang ditawarkan di sini.
        // Menawarkan seluruh daftar izin akan mengundang orang menambal peran
        // lewat pengguna satu per satu — dan itu justru menghilangkan gunanya
        // peran. Yang tersisa: izin khusus yang sengaja tak dimiliki peran.
        // super_admin DIKECUALIKAN dari perhitungan: seeder memberinya
        // `Permission::all()`, jadi memasukkannya membuat daftar ini selalu
        // kosong — setiap izin akan selalu "sudah dimiliki peran".
        $roleOwned = Permission::query()
            ->whereHas('roles', fn ($q) => $q->where('name', '!=', 'super_admin'))
            ->pluck('name');

        $permissions = Permission::query()
            ->whereNotIn('name', $roleOwned)
            ->orderBy('name')
            ->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$name => RoleForm::labelFor($name)])
            ->all();

        return view('livewire.system.user-form', [
            'roles' => $roles,
            'permissions' => $permissions,
        ])->layout('components.layouts.app', [
            'title' => $this->userId ? 'Edit Pengguna' : 'Tambah Pengguna',
        ]);
    }
}
