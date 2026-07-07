<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // Akun dinonaktifkan → tolak login sepenuhnya (bukan sekadar batasi panel).
        if (! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Akun Anda dinonaktifkan. Silakan hubungi pengurus koperasi.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        // Satu akun hanya boleh aktif di SATU perangkat. Cabut remember-token
        // lama (mematikan recaller perangkat lain), terbitkan ulang sesi untuk
        // perangkat ini, lalu hapus semua sesi milik akun ini kecuali sesi
        // sekarang → perangkat lain otomatis ter-logout di request berikutnya.
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        Auth::login($user, $this->remember);
        $user->invalidateSessions(session()->getId());

        // Email belum terverifikasi → tetap boleh masuk, tetapi diarahkan ke
        // halaman profil untuk verifikasi lebih dulu (soft gate, bukan blokir).
        if (! $user->hasVerifiedEmail()) {
            session()->flash('toast', [
                'type' => 'warning',
                'message' => 'Email Anda belum terverifikasi. Silakan verifikasi terlebih dahulu — klik "Kirim ulang link verifikasi" di bawah.',
            ]);

            return $this->redirectRoute('profile.edit', navigate: true);
        }

        return $this->redirectIntended(default: route('dashboard'), navigate: true);
    }

    /**
     * Pastikan request tidak melewati batas percobaan (5x per email+IP).
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.login')
            ->layout('components.layouts.guest', ['title' => 'Masuk']);
    }
}
