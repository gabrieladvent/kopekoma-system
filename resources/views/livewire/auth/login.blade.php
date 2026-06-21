<div class="grid min-h-screen lg:grid-cols-2">
    {{-- Panel brand (signature) — gradient + orbs, sembunyi di mobile --}}
    <div class="bg-brand-gradient relative hidden overflow-hidden p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-20 -left-10 h-72 w-72 rounded-full bg-black/10 blur-3xl"></div>

        <div class="relative flex items-center gap-2.5">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-white/15 text-sm font-bold backdrop-blur">K</span>
            <span class="text-sm font-bold tracking-tight">KOPEKOMA</span>
        </div>

        <div class="relative max-w-md">
            <h2 class="text-3xl font-bold leading-tight tracking-tight">Kelola koperasi dengan tenang.</h2>
            <p class="mt-3 text-sm text-white/80">
                Simpanan, pinjaman, dan laporan dalam satu tempat — rapi, akurat, dan mudah dipakai bersama tim.
            </p>
            <ul class="mt-8 space-y-3 text-sm text-white/90">
                @foreach (['Data anggota & simpanan terpusat', 'Laporan keuangan otomatis', 'Akses berbasis peran yang aman'] as $point)
                    <li class="flex items-center gap-3">
                        <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-white/20">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        </span>
                        {{ $point }}
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="relative text-xs text-white/60">© {{ date('Y') }} KOPEKOMA — Sistem Koperasi</p>
    </div>

    {{-- Form --}}
    <div x-data class="relative flex items-center justify-center p-6 sm:p-10">
        {{-- Theme toggle --}}
        <button type="button" @click="$store.theme.toggle()"
                class="absolute right-5 top-5 grid h-9 w-9 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/50 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                aria-label="Ganti tema">
            <svg x-show="!$store.theme.dark" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
            <svg x-show="$store.theme.dark" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/></svg>
        </button>

        <div class="w-full max-w-sm">
            {{-- Logo (mobile) --}}
            <div class="mb-8 flex items-center gap-2.5 lg:hidden">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-brand-gradient text-sm font-bold text-white">K</span>
                <span class="text-sm font-bold tracking-tight">KOPEKOMA</span>
            </div>

            <h1 class="text-2xl font-bold tracking-tight">Masuk ke akun</h1>
            <p class="mt-1.5 text-sm text-muted">Selamat datang kembali. Silakan masukkan kredensial Anda.</p>

            <form wire:submit="login" class="mt-8 space-y-5">
                <x-ui.input
                    label="Email"
                    type="email"
                    name="email"
                    autocomplete="username"
                    placeholder="nama@koperasi.id"
                    wire:model="email"
                    :error="$errors->first('email')"
                />

                <div class="space-y-1">
                    <div class="flex items-center justify-between">
                        <label for="password" class="block text-sm font-medium text-text">Kata Sandi</label>
                        <a href="#" class="text-xs font-medium text-primary hover:underline">Lupa sandi?</a>
                    </div>
                    <div x-data="{ show: false }" class="relative">
                        <input
                            :type="show ? 'text' : 'password'"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            wire:model="password"
                            @class([
                                'w-full rounded-lg border bg-surface px-3 h-10 pr-10 text-sm text-text placeholder:text-muted transition duration-150 ease-out focus-visible:ring-2 focus-visible:outline-none',
                                'border-border focus-visible:ring-primary' => ! $errors->has('password'),
                                'border-danger focus-visible:ring-danger' => $errors->has('password'),
                            ])
                            placeholder="••••••••"
                        >
                        <button type="button" @click="show = !show" tabindex="-1"
                                class="absolute right-2 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-muted transition hover:text-text"
                                :aria-label="show ? 'Sembunyikan sandi' : 'Tampilkan sandi'">
                            <svg x-show="!show" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            <svg x-show="show" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88"/></svg>
                        </button>
                    </div>
                    @error('password') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-muted">
                    <input type="checkbox" wire:model="remember"
                           class="h-4 w-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                    Ingat saya
                </label>

                <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="login">
                    <svg wire:loading wire:target="login" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    <span wire:loading.remove wire:target="login">Masuk</span>
                    <span wire:loading wire:target="login">Memproses…</span>
                </x-ui.button>
            </form>
        </div>
    </div>
</div>
