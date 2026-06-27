<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-danger/10 text-danger">
                <x-ui.icon name="no-symbol" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Blacklist Pinjaman</h2>
                <p class="mt-0.5 text-sm text-muted">Anggota yang sedang diblokir tidak dapat mengajukan pinjaman baru.</p>
            </div>
        </div>

        @can('create_loan::blacklist')
            <x-ui.button variant="danger" wire:click="openCreate">
                <x-ui.icon name="plus" class="h-4.5 w-4.5" />
                Tandai Blacklist
            </x-ui.button>
        @endcan
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative w-full lg:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari anggota…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="active"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Status</option>
                <option value="1">Aktif</option>
                <option value="0">Dilepas</option>
            </select>

            @if ($this->hasActiveFilters())
                <button type="button" wire:click="clearFilters"
                        class="inline-flex h-10 shrink-0 items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-medium text-danger transition hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none">
                    <x-ui.icon name="x" class="h-4 w-4" /> Bersihkan
                </button>
            @endif
        </div>
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-5 py-3 text-left">Anggota</th>
                        <th class="px-5 py-3 text-left">Alasan</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="px-5 py-3 text-left">Tgl Blacklist</th>
                        <th class="px-5 py-3 text-left">Dicatat Oleh</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 4; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-4 w-36 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-48 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-16 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-8 w-8 animate-pulse rounded-lg bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($blacklists as $bl)
                        <tr class="transition hover:bg-bg/60" wire:key="bl-{{ $bl->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('loans.blacklist.show', $bl) }}" wire:navigate class="font-medium text-text hover:text-primary">
                                    {{ $bl->member?->full_name ?? '—' }}
                                </a>
                                <p class="font-mono text-xs text-muted">{{ $bl->member?->member_number }}</p>
                            </td>
                            <td class="px-5 py-4 max-w-xs">
                                <p class="line-clamp-2 text-muted">{{ $bl->reason }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$bl->is_active ? 'danger' : 'neutral'">{{ $bl->is_active ? 'Aktif' : 'Dilepas' }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-text">{{ $bl->blacklisted_at?->translatedFormat('d M Y') }}</td>
                            <td class="px-5 py-4 text-muted">{{ $bl->recordedBy?->name ?? '—' }}</td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="eye" :href="route('loans.blacklist.show', $bl)" wire:navigate>Lihat Detail</x-ui.dropdown-item>
                                        @if ($bl->is_active && (auth()->user()?->can('update', $bl) ?? false))
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="lock-open" variant="warning"
                                                wire:click="openRelease('{{ $bl->id }}')">Lepas Blacklist</x-ui.dropdown-item>
                                        @endif
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-danger/10 text-danger">
                                        <x-ui.icon name="no-symbol" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $this->hasActiveFilters() ? 'Tidak ada data yang cocok' : 'Belum ada anggota di blacklist' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $this->hasActiveFilters() ? 'Coba ubah kata kunci atau filter.' : 'Semua anggota dapat mengajukan pinjaman.' }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($blacklists->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $blacklists->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Modal: Tambah blacklist --}}
    <div x-data="{ show: @entangle('showCreate').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @keydown.escape.window="show = false"
             role="dialog" aria-modal="true"
             class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-danger/10 text-danger">
                    <x-ui.icon name="no-symbol" class="h-5 w-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-text">Tandai Blacklist</h3>
                    <p class="mt-1 text-xs text-muted">Anggota tidak akan bisa mengajukan pinjaman baru sampai dilepas.</p>
                </div>
            </div>

            <form wire:submit="store" class="mt-5 space-y-4">
                @include('livewire.savings.partials.member-picker', ['label' => 'Anggota'])

                <div class="space-y-1">
                    <label for="blacklisted_at" class="block text-sm font-medium text-text">Tanggal Blacklist</label>
                    <input id="blacklisted_at" type="date" wire:model="blacklisted_at"
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                               'border-border' => ! $errors->has('blacklisted_at'),
                               'border-danger focus-visible:ring-danger' => $errors->has('blacklisted_at'),
                           ])>
                    @error('blacklisted_at')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="space-y-1">
                    <label for="reason" class="block text-sm font-medium text-text">Alasan</label>
                    <textarea id="reason" wire:model="reason" rows="3" placeholder="Wajib, minimal 5 karakter."
                              @class([
                                  'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                  'border-border' => ! $errors->has('reason'),
                                  'border-danger focus-visible:ring-danger' => $errors->has('reason'),
                              ])></textarea>
                    @error('reason')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="store">
                        <svg wire:loading wire:target="store" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Tandai Blacklist
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: Lepas --}}
    <div x-data="{ show: @entangle('showRelease').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @keydown.escape.window="show = false"
             role="dialog" aria-modal="true"
             class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-warning/10 text-warning">
                    <x-ui.icon name="lock-open" class="h-5 w-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-text">Lepas Blacklist</h3>
                    <p class="mt-1 text-xs text-muted">Anggota akan kembali dapat mengajukan pinjaman.</p>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                <x-ui.button type="button" wire:click="performRelease" wire:loading.attr="disabled" wire:target="performRelease"
                             class="bg-warning text-white hover:opacity-90">
                    <svg wire:loading wire:target="performRelease" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    Lepas Blacklist
                </x-ui.button>
            </div>
        </div>
    </div>

    <x-ui.toast-host />
</div>
