@php
    $initials = \Illuminate\Support\Str::of($user->name)
        ->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
@endphp

<div class="mx-auto max-w-full space-y-6">
    {{-- Header --}}
    <div>
        <h2 class="text-2xl font-bold tracking-tight">Profil Saya</h2>
        <p class="mt-1 text-sm text-muted">Kelola foto, email, dan password akun Anda.</p>
    </div>

    {{-- Foto Profil --}}
    <div x-data="avatarCropper()">
        <x-ui.card title="Foto Profil" subtitle="Format JPG, PNG, atau WEBP. Maksimal 2 MB. Bisa di-crop saat unggah.">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
                {{-- Preview --}}
                <div class="shrink-0">
                    @if ($photo && $photo->isPreviewable())
                        <img src="{{ $photo->temporaryUrl() }}" alt="Pratinjau"
                             class="h-24 w-24 rounded-full object-cover ring-2 ring-border">
                    @elseif ($user->avatarUrl())
                        <img src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}"
                             class="h-24 w-24 rounded-full object-cover ring-2 ring-border">
                    @else
                        <div class="grid h-24 w-24 place-items-center rounded-full bg-secondary/15 text-2xl font-semibold text-secondary">
                            {{ $initials }}
                        </div>
                    @endif
                </div>

                {{-- Kontrol --}}
                <div class="flex-1 space-y-3">
                    <div class="space-y-1">
                        <label for="photo"
                               class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-border px-4 text-sm font-medium text-text transition duration-150 ease-out hover:bg-border/50">
                            <x-ui.icon name="arrow-up-tray" class="h-4 w-4" />
                            Pilih Foto
                        </label>
                        <input type="file" id="photo" x-ref="input" @change="pickFile" accept="image/*" class="hidden">
                        @error('photo') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                        <p wire:loading wire:target="photo" class="text-xs text-muted">Mengunggah…</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button wire:click="savePhoto" wire:loading.attr="disabled" wire:target="savePhoto,photo">
                            <span wire:loading.remove wire:target="savePhoto">Simpan Foto</span>
                            <span wire:loading wire:target="savePhoto">Menyimpan…</span>
                        </x-ui.button>
                        @if ($user->avatar_path)
                            <x-ui.button variant="ghost"
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
            </div>
        </x-ui.card>

        {{-- Modal cropper --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-60 flex items-center justify-center p-4"
             @keydown.escape.window="close()">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="close()"></div>

            <div x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
                {{-- Header --}}
                <div class="flex items-center justify-between border-b border-border px-6 py-4">
                    <h3 class="text-sm font-semibold tracking-tight text-text">Atur Foto Profil</h3>
                    <button type="button" @click="close()"
                            class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text"
                            aria-label="Tutup">
                        <x-ui.icon name="x" class="h-4.5 w-4.5" />
                    </button>
                </div>

                {{-- Area crop --}}
                <div class="space-y-4 p-6">
                    <div class="overflow-hidden rounded-xl bg-black/80">
                        <img x-ref="image" :src="imageSrc" alt="" class="block max-h-[60vh] w-full">
                    </div>

                    {{-- Kontrol crop --}}
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

                {{-- Footer --}}
                <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                    <x-ui.button variant="ghost" x-on:click="close()">Batal</x-ui.button>
                    <x-ui.button x-on:click="apply()" x-bind:disabled="loading">
                        <span x-show="! loading">Terapkan</span>
                        <span x-show="loading" x-cloak>Memproses…</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>

    {{-- Informasi Akun --}}
    <x-ui.card title="Informasi Akun" subtitle="Mengganti email memerlukan konfirmasi password & verifikasi ulang.">
        <form wire:submit="saveAccount" class="space-y-4">
            <x-ui.input label="Nama" name="name" wire:model="name" :error="$errors->first('name')" />

            <div class="space-y-1">
                <x-ui.input label="Email" name="email" type="email" wire:model="email"
                            :error="$errors->first('email')" />
                @if ($user->hasVerifiedEmail())
                    <p class="inline-flex items-center gap-1 text-xs text-success">
                        <x-ui.icon name="check" class="h-3.5 w-3.5" /> Email terverifikasi
                    </p>
                @else
                    <p class="flex flex-wrap items-center gap-2 text-xs text-warning">
                        <span class="inline-flex items-center gap-1">
                            <x-ui.icon name="exclamation-triangle" class="h-3.5 w-3.5" /> Email belum terverifikasi.
                        </span>
                        <button type="button" wire:click="resendVerification"
                                class="font-medium text-primary underline-offset-2 hover:underline">
                            Kirim ulang link verifikasi
                        </button>
                    </p>
                @endif
            </div>

            <x-ui.input label="Password Saat Ini" name="account_current_password" type="password"
                        wire:model="account_current_password" autocomplete="current-password"
                        hint="Hanya diperlukan jika Anda mengubah email."
                        :error="$errors->first('account_current_password')" />

            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveAccount">
                    <span wire:loading.remove wire:target="saveAccount">Simpan Perubahan</span>
                    <span wire:loading wire:target="saveAccount">Menyimpan…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Ubah Password --}}
    <x-ui.card title="Ubah Password" subtitle="Gunakan password yang kuat dan unik.">
        <form wire:submit="savePassword" class="space-y-4">
            <x-ui.input label="Password Saat Ini" name="current_password" type="password"
                        wire:model="current_password" autocomplete="current-password"
                        :error="$errors->first('current_password')" />
            <x-ui.input label="Password Baru" name="password" type="password"
                        wire:model="password" autocomplete="new-password"
                        :error="$errors->first('password')" />
            <x-ui.input label="Konfirmasi Password Baru" name="password_confirmation" type="password"
                        wire:model="password_confirmation" autocomplete="new-password" />

            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="savePassword">
                    <span wire:loading.remove wire:target="savePassword">Ubah Password</span>
                    <span wire:loading wire:target="savePassword">Menyimpan…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
