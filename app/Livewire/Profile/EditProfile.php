<?php

namespace App\Livewire\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\WithFileUploads;

class EditProfile extends Component
{
    use WithFileUploads;

    // --- Informasi akun ---
    public string $name = '';

    public string $email = '';

    /** Password saat ini, wajib saat email berubah. */
    public string $account_current_password = '';

    // --- Ubah password ---
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    // --- Foto profil ---
    public $photo = null;

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    /**
     * Simpan nama & email. Bila email berubah: wajib konfirmasi password saat
     * ini, reset status verifikasi, lalu kirim ulang link verifikasi.
     */
    public function saveAccount(): void
    {
        $user = $this->user();

        $emailChanged = $this->email !== $user->email;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ];

        if ($emailChanged) {
            $rules['account_current_password'] = ['required', 'current_password'];
        }

        $this->validate($rules, attributes: [
            'account_current_password' => 'password saat ini',
        ]);

        $user->name = $this->name;

        if ($emailChanged) {
            $user->email = $this->email;
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        $this->reset('account_current_password');

        $this->dispatch('toast', type: 'success', message: $emailChanged
            ? 'Email diperbarui. Link verifikasi telah dikirim ke alamat baru.'
            : 'Informasi akun diperbarui.');
    }

    /**
     * Ganti password. Wajib konfirmasi password saat ini.
     */
    public function savePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ], messages: [
            'password.confirmed' => 'Konfirmasi password baru tidak cocok.',
            'password.min' => 'Password baru minimal 8 karakter.',
        ], attributes: [
            'current_password' => 'password saat ini',
            'password' => 'password baru',
        ]);

        $this->user()->update(['password' => $this->password]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('toast', type: 'success', message: 'Password berhasil diubah.');
    }

    /**
     * Unggah / ganti foto profil. File lama dihapus saat diganti.
     */
    public function savePhoto(): void
    {
        $this->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], attributes: ['photo' => 'foto']);

        $user = $this->user();
        $old = $user->avatar_path;

        $path = $this->photo->store('avatars', 'public');

        $user->update(['avatar_path' => $path]);

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->reset('photo');

        $this->dispatch('toast', type: 'success', message: 'Foto profil diperbarui.');
    }

    /**
     * Hapus foto profil; kembali ke inisial nama.
     */
    public function removePhoto(): void
    {
        $user = $this->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        $this->reset('photo');

        $this->dispatch('toast', type: 'success', message: 'Foto profil dihapus.');
    }

    /**
     * Kirim ulang link verifikasi email.
     */
    public function resendVerification(): void
    {
        $user = $this->user();

        if ($user->hasVerifiedEmail()) {
            $this->dispatch('toast', type: 'success', message: 'Email Anda sudah terverifikasi.');

            return;
        }

        $user->sendEmailVerificationNotification();

        $this->dispatch('toast', type: 'success', message: 'Link verifikasi telah dikirim ulang.');
    }

    public function render()
    {
        return view('livewire.profile.edit-profile', [
            'user' => $this->user(),
        ])->layout('components.layouts.app', ['title' => 'Profil Saya']);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
