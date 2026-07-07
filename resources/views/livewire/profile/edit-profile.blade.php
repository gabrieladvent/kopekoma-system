@php
    $initials = \Illuminate\Support\Str::of($user->name)
        ->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
    $role = $user->getRoleNames()->first();
@endphp

<div class="mx-auto max-w-4xl space-y-6" x-data="avatarCropper()">
    {{-- ============ Hero: identitas akun ============ --}}
    <x-ui.card class="overflow-hidden p-0">
        <div class="relative h-24 bg-linear-to-r from-primary/20 via-primary/10 to-secondary/15">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true"></div>
        </div>

        <div class="flex flex-col gap-4 px-6 pb-6 sm:flex-row sm:items-end">
            {{-- Avatar --}}
            <div class="-mt-12 shrink-0">
                <div class="relative inline-block">
                    @if ($photo && $photo->isPreviewable())
                        <img src="{{ $photo->temporaryUrl() }}" alt="Pratinjau"
                             class="h-24 w-24 rounded-2xl object-cover ring-4 ring-surface">
                    @elseif ($user->avatarUrl())
                        <img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}"
                             class="h-24 w-24 rounded-2xl object-cover ring-4 ring-surface">
                    @else
                        <div class="grid h-24 w-24 place-items-center rounded-2xl bg-secondary/15 text-2xl font-semibold text-secondary ring-4 ring-surface">
                            {{ $initials }}
                        </div>
                    @endif

                    <label for="photo" title="Ganti foto"
                           class="absolute -bottom-1.5 -right-1.5 grid h-8 w-8 cursor-pointer place-items-center rounded-full border border-border bg-surface text-muted shadow-sm transition hover:text-primary">
                        <x-ui.icon name="arrow-up-tray" class="h-4 w-4" />
                    </label>
                    <input type="file" id="photo" x-ref="input" @change="pickFile" accept="image/*" class="hidden">
                </div>
            </div>

            {{-- Nama & meta --}}
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-xl font-bold tracking-tight text-text">{{ $user->name }}</h2>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
                    <span class="truncate">{{ $user->email }}</span>
                    @if ($role)
                        <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">{{ $role }}</span>
                    @endif
                    @if ($user->hasVerifiedEmail())
                        <span class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                            <x-ui.icon name="check" class="h-3 w-3" /> Terverifikasi
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
                            <x-ui.icon name="exclamation-triangle" class="h-3 w-3" /> Belum verifikasi
                        </span>
                    @endif
                </div>
            </div>

            {{-- Aksi foto --}}
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($photo)
                    <x-ui.button wire:click="savePhoto" wire:loading.attr="disabled" wire:target="savePhoto,photo" class="h-9 px-3">
                        <span wire:loading.remove wire:target="savePhoto">Simpan Foto</span>
                        <span wire:loading wire:target="savePhoto">Menyimpan…</span>
                    </x-ui.button>
                @endif
                @if ($user->avatar_path)
                    <x-ui.button variant="ghost" class="h-9 px-3"
                                 x-on:click="$dispatch('confirm-action', {
                                     title: 'Hapus foto profil?',
                                     message: 'Foto akan dihapus dan diganti inisial nama.',
                                     confirmLabel: 'Hapus', variant: 'danger',
                                     method: 'removePhoto',
                                 })">
                        Hapus Foto
                    </x-ui.button>
                @endif
            </div>
        </div>

        @error('photo') <p class="px-6 pb-4 text-xs text-danger">{{ $message }}</p> @enderror
        <p wire:loading wire:target="photo" class="px-6 pb-4 text-xs text-muted">Mengunggah foto…</p>
    </x-ui.card>

    {{-- Banner verifikasi email (jika belum) --}}
    @unless ($user->hasVerifiedEmail())
        <div class="flex flex-col gap-3 rounded-2xl border border-warning/30 bg-warning/5 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-warning/15 text-warning">
                    <x-ui.icon name="exclamation-triangle" class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-text">Email belum terverifikasi</p>
                    <p class="text-xs text-muted">Verifikasi email Anda agar dapat menerima notifikasi penting dan memulihkan akun.</p>
                </div>
            </div>
            <x-ui.button wire:click="resendVerification" wire:loading.attr="disabled" wire:target="resendVerification" class="h-9 shrink-0 px-3">
                <svg wire:loading wire:target="resendVerification" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <span wire:loading.remove wire:target="resendVerification">Kirim Link Verifikasi</span>
                <span wire:loading wire:target="resendVerification">Mengirim…</span>
            </x-ui.button>
        </div>
    @endunless

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- ============ Informasi Akun ============ --}}
        <x-ui.card title="Informasi Akun" subtitle="Mengganti email memerlukan konfirmasi password & verifikasi ulang.">
            <form wire:submit="saveAccount" class="space-y-4">
                <x-ui.input label="Nama" name="name" wire:model="name" :error="$errors->first('name')" />

                <x-ui.input label="Email" name="email" type="email" wire:model="email"
                            :error="$errors->first('email')" />

                <x-ui.input label="Password Saat Ini" name="account_current_password" type="password"
                            wire:model="account_current_password" autocomplete="current-password"
                            hint="Hanya diperlukan jika Anda mengubah email."
                            :error="$errors->first('account_current_password')" />

                <div class="flex justify-end pt-1">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveAccount">
                        <span wire:loading.remove wire:target="saveAccount">Simpan Perubahan</span>
                        <span wire:loading wire:target="saveAccount">Menyimpan…</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- ============ Ubah Password ============ --}}
        <x-ui.card title="Ubah Password" subtitle="Minimal 8 karakter, mengandung huruf dan angka.">
            <form wire:submit="savePassword" class="space-y-4"
                  x-data="{ pwd: @entangle('password'), confirm: @entangle('password_confirmation'),
                            get hasLen() { return this.pwd.length >= 8 },
                            get hasLetter() { return /[a-zA-Z]/.test(this.pwd) },
                            get hasNumber() { return /[0-9]/.test(this.pwd) },
                            get match() { return this.pwd.length > 0 && this.pwd === this.confirm } }">
                <x-ui.input label="Password Saat Ini" name="current_password" type="password"
                            wire:model="current_password" autocomplete="current-password"
                            :error="$errors->first('current_password')" />
                <x-ui.input label="Password Baru" name="password" type="password"
                            wire:model="password" autocomplete="new-password"
                            :error="$errors->first('password')" />

                {{-- Checklist syarat, live --}}
                <ul class="space-y-1 text-xs" x-show="pwd.length > 0" x-cloak>
                    <li class="flex items-center gap-1.5" :class="hasLen ? 'text-success' : 'text-muted'">
                        <span x-text="hasLen ? '✓' : '○'"></span> Minimal 8 karakter
                    </li>
                    <li class="flex items-center gap-1.5" :class="hasLetter ? 'text-success' : 'text-muted'">
                        <span x-text="hasLetter ? '✓' : '○'"></span> Mengandung huruf
                    </li>
                    <li class="flex items-center gap-1.5" :class="hasNumber ? 'text-success' : 'text-muted'">
                        <span x-text="hasNumber ? '✓' : '○'"></span> Mengandung angka
                    </li>
                </ul>

                <div class="space-y-1">
                    <x-ui.input label="Konfirmasi Password Baru" name="password_confirmation" type="password"
                                wire:model="password_confirmation" autocomplete="new-password" />
                    <p x-show="confirm.length > 0 && ! match" x-cloak class="text-xs text-danger">Konfirmasi password tidak cocok.</p>
                    <p x-show="match" x-cloak class="text-xs text-success">Password cocok.</p>
                </div>

                <div class="flex justify-end pt-1">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="savePassword">
                        <span wire:loading.remove wire:target="savePassword">Ubah Password</span>
                        <span wire:loading wire:target="savePassword">Menyimpan…</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>

    {{-- ============ Modal cropper foto ============ --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-60 flex items-center justify-center p-4"
         @keydown.escape.window="close()">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="close()"></div>

        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <h3 class="text-sm font-semibold tracking-tight text-text">Atur Foto Profil</h3>
                <button type="button" @click="close()"
                        class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text"
                        aria-label="Tutup">
                    <x-ui.icon name="x" class="h-4.5 w-4.5" />
                </button>
            </div>

            <div class="space-y-4 p-6">
                <div class="overflow-hidden rounded-xl bg-black/80">
                    <img x-ref="image" :src="imageSrc" alt="" class="block max-h-[60vh] w-full">
                </div>

                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-muted">Zoom</span>
                        <button type="button" @click="zoom(-0.1)"
                                class="grid h-8 w-8 place-items-center rounded-lg border border-border text-text transition hover:bg-border/50">−</button>
                        <button type="button" @click="zoom(0.1)"
                                class="grid h-8 w-8 place-items-center rounded-lg border border-border text-text transition hover:bg-border/50">+</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-muted">Putar</span>
                        <button type="button" @click="rotate(-90)"
                                class="inline-flex h-8 items-center gap-1 rounded-lg border border-border px-2.5 text-xs text-text transition hover:bg-border/50">
                            <x-ui.icon name="arrow-uturn-left" class="h-3.5 w-3.5" /> 90°
                        </button>
                        <button type="button" @click="rotate(90)"
                                class="inline-flex h-8 items-center gap-1 rounded-lg border border-border px-2.5 text-xs text-text transition hover:bg-border/50">
                            90° <x-ui.icon name="arrow-uturn-left" class="h-3.5 w-3.5 -scale-x-100" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <x-ui.button variant="ghost" x-on:click="close()">Batal</x-ui.button>
                <x-ui.button x-on:click="apply()" x-bind:disabled="loading">
                    <span x-show="! loading">Terapkan</span>
                    <span x-show="loading" x-cloak>Memproses…</span>
                </x-ui.button>
            </div>
        </div>
    </div>

    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
