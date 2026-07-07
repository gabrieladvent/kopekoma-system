@php($isEdit = filled($userId))
@php($self = $this->isSelf())
<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('system.users') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar pengguna
    </a>

    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
            <x-ui.icon name="users" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">{{ $isEdit ? 'Edit Pengguna' : 'Tambah Pengguna' }}</h2>
            <p class="mt-0.5 text-sm text-muted">Atur identitas, kata sandi, peran, dan status akun.</p>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Identitas --}}
        <x-ui.card>
            <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-text">
                <x-ui.icon name="user" class="h-5 w-5 text-primary" /> Identitas
            </h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="name" class="block text-sm font-medium text-text">Nama</label>
                    <input id="name" type="text" wire:model="name" placeholder="Nama lengkap"
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                               'border-border' => ! $errors->has('name'),
                               'border-danger focus-visible:ring-danger' => $errors->has('name'),
                           ])>
                    @error('name')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1">
                    <label for="email" class="block text-sm font-medium text-text">Email</label>
                    <input id="email" type="email" wire:model="email" placeholder="nama@kopekoma.test"
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                               'border-border' => ! $errors->has('email'),
                               'border-danger focus-visible:ring-danger' => $errors->has('email'),
                           ])>
                    @error('email')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-ui.card>

        {{-- Keamanan --}}
        <x-ui.card>
            <h3 class="mb-1 flex items-center gap-2 text-sm font-semibold text-text">
                <x-ui.icon name="key" class="h-5 w-5 text-primary" /> Kata Sandi
            </h3>
            @if ($isEdit)
                <p class="mb-4 text-xs text-muted">Kosongkan bila tidak ingin mengubah kata sandi.</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="password" class="block text-sm font-medium text-text">Kata Sandi Baru</label>
                        <input id="password" type="password" wire:model="password" autocomplete="new-password"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('password'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('password'),
                               ])>
                        @error('password')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="password_confirmation" class="block text-sm font-medium text-text">Konfirmasi Kata Sandi</label>
                        <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password"
                               class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                    </div>
                </div>
            @else
                <div class="mt-2 flex items-start gap-2.5 rounded-xl border border-dashed border-border bg-bg/50 px-4 py-3">
                    <x-ui.icon name="key" class="mt-0.5 h-4.5 w-4.5 shrink-0 text-muted" />
                    <p class="text-xs text-muted">Kata sandi akan <span class="font-medium text-text">dibuat otomatis</span> dan ditampilkan sekali setelah pengguna disimpan, agar dapat Anda salin dan berikan ke pengguna.</p>
                </div>
            @endif
        </x-ui.card>

        {{-- Peran & Status --}}
        <x-ui.card>
            <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-text">
                <x-ui.icon name="shield" class="h-5 w-5 text-primary" /> Peran & Status
            </h3>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-text">Peran</label>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    @foreach ($roles as $role)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-border px-3 py-2.5 text-sm text-text transition hover:bg-bg/60">
                            <input type="checkbox" value="{{ $role }}" wire:model="selectedRoles"
                                   class="h-4 w-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                            {{ $role }}
                        </label>
                    @endforeach
                </div>
                @error('selectedRoles')<p class="text-xs text-danger">{{ $message }}</p>@enderror
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="flex items-center justify-between gap-3 rounded-xl border border-border px-4 py-3 {{ $self ? 'opacity-60' : 'cursor-pointer' }}">
                    <span>
                        <span class="block text-sm font-medium text-text">Akun Aktif</span>
                        <span class="block text-xs text-muted">{{ $self ? 'Tidak dapat menonaktifkan akun sendiri.' : 'Pengguna nonaktif tidak bisa login.' }}</span>
                    </span>
                    <input type="checkbox" wire:model="is_active" @disabled($self)
                           class="h-4 w-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                </label>
                <label class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-border px-4 py-3">
                    <span>
                        <span class="block text-sm font-medium text-text">Email Terverifikasi</span>
                        <span class="block text-xs text-muted">Tandai bila email sudah terverifikasi.</span>
                    </span>
                    <input type="checkbox" wire:model="email_verified"
                           class="h-4 w-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                </label>
            </div>
        </x-ui.card>

        {{-- Actions --}}
        <div class="flex items-center justify-end gap-3">
            <x-ui.button variant="ghost" :href="route('system.users')" wire:navigate>Batal</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Pengguna' }}
            </x-ui.button>
        </div>
    </form>

    {{-- Modal kredensial: tampil sekali setelah create berhasil. --}}
    <div x-data="{ show: @entangle('showCredentials'), copied: false,
                   copy() {
                       navigator.clipboard.writeText($refs.pwd.value).then(() => {
                           this.copied = true;
                           setTimeout(() => this.copied = false, 2000);
                       });
                   } }"
         x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40"></div>
        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             role="dialog" aria-modal="true"
             class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-success/10 text-success">
                    <x-ui.icon name="check" class="h-5 w-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-text">Pengguna berhasil dibuat</h3>
                    <p class="mt-1 text-xs text-muted">Salin kata sandi berikut dan berikan ke pengguna. Kata sandi ini <span class="font-medium text-danger">hanya ditampilkan sekali</span> dan tidak dapat dilihat lagi.</p>
                </div>
            </div>

            <div class="mt-5 space-y-1.5">
                <label class="block text-sm font-medium text-text">Kata Sandi</label>
                <div class="flex items-center gap-2">
                    <input x-ref="pwd" type="text" readonly value="{{ $generatedPassword }}"
                           class="h-10 w-full rounded-lg border border-border bg-bg px-3 font-mono text-sm text-text focus-visible:outline-none">
                    <button type="button" @click="copy()"
                            class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-text transition hover:bg-border/50">
                        <x-ui.icon name="document" class="h-4 w-4" />
                        <span x-show="! copied">Salin</span>
                        <span x-show="copied" x-cloak class="text-success">Tersalin!</span>
                    </button>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <x-ui.button type="button" wire:click="finishCreate">Selesai</x-ui.button>
            </div>
        </div>
    </div>

    <x-ui.toast-host />
</div>
