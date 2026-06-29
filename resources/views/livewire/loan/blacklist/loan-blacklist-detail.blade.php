<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('loans.blacklist') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl {{ $blacklist->is_active ? 'bg-danger/10 text-danger' : 'bg-border text-muted' }}">
                <x-ui.icon name="no-symbol" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$blacklist->is_active ? 'danger' : 'neutral'">{{ $blacklist->is_active ? 'Blacklist Aktif' : 'Dilepas' }}</x-ui.badge>
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $blacklist->member?->full_name ?? '—' }}</h2>
                <p class="font-mono text-sm text-muted">{{ $blacklist->member?->member_number }}</p>
            </div>
        </div>

        @if ($this->canRelease($blacklist))
            <div class="shrink-0">
                <x-ui.button wire:click="openRelease" class="bg-warning text-white hover:opacity-90">
                    <x-ui.icon name="lock-open" class="h-4 w-4" /> Lepas Blacklist
                </x-ui.button>
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                {{-- Alasan highlight --}}
                <div class="rounded-xl bg-danger/5 px-4 py-4 ring-1 ring-inset ring-danger/15">
                    <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-danger">
                        <x-ui.icon name="exclamation-triangle" class="h-3.5 w-3.5" /> Alasan Blacklist
                    </p>
                    <p class="mt-1.5 text-sm leading-relaxed text-text">{{ $blacklist->reason }}</p>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5">
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Tgl Blacklist
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $blacklist->blacklisted_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="lock-open" class="h-3.5 w-3.5" /> Tgl Dilepas
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $blacklist->released_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="building-office" class="h-3.5 w-3.5" /> OPD / Instansi
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $blacklist->member?->agency?->agency_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="user" class="h-3.5 w-3.5" /> Dicatat Oleh
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $blacklist->recordedBy?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Dicatat Pada
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $blacklist->created_at?->translatedFormat('d M Y · H.i') }} WIB</dd>
                    </div>
                </dl>

                {{-- Tautan ke pinjaman anggota --}}
                @if ($blacklist->member)
                    <div class="mt-5 border-t border-border pt-4">
                        <a href="{{ route('loans.index', ['q' => $blacklist->member->member_number]) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 text-sm font-medium text-primary transition hover:underline">
                            <x-ui.icon name="banknotes" class="h-4 w-4" /> Lihat riwayat pinjaman anggota
                        </a>
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- Audit Trail --}}
        <div>
            <x-ui.card>
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-text">Audit Trail</h3>
                    <span class="text-xs text-muted">Klik untuk detail</span>
                </div>
                <div class="mt-4">
                    @include('livewire.master.partials.audit-trail')
                </div>
            </x-ui.card>
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
