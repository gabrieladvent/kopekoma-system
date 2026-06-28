{{-- Bell notifikasi (database notifications Filament). Poll tiap 30s untuk count baru. --}}
<div class="relative" x-data="{ open: false }" wire:poll.30s>
    <button type="button" @click="open = ! open" :aria-expanded="open" aria-label="Notifikasi"
            class="relative grid h-9 w-9 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/50 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        <x-ui.icon name="bell" class="h-5 w-5" />
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-none text-white ring-2 ring-surface">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         @click.outside="open = false" @keydown.escape.window="open = false"
         class="absolute right-0 z-50 mt-2 w-80 origin-top-right overflow-hidden rounded-xl border border-border bg-surface shadow-lg sm:w-96">
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <h3 class="text-sm font-semibold text-text">Notifikasi</h3>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllAsRead"
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary transition hover:underline">
                    <x-ui.icon name="check" class="h-3.5 w-3.5" /> Tandai semua dibaca
                </button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            @forelse ($notifications as $n)
                @php($d = $n->data)
                @php($color = match ($d['status'] ?? 'info') {
                    'success' => 'bg-success',
                    'warning' => 'bg-warning',
                    'danger' => 'bg-danger',
                    default => 'bg-primary',
                })
                <button type="button" wire:click="markAsRead('{{ $n->id }}')" wire:key="notif-{{ $n->id }}"
                        @class([
                            'flex w-full items-start gap-3 border-b border-border px-4 py-3 text-left transition hover:bg-bg/60',
                            'bg-primary/5' => is_null($n->read_at),
                        ])>
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ is_null($n->read_at) ? $color : 'bg-transparent ring-1 ring-border' }}"></span>
                    <div class="min-w-0 flex-1">
                        <p @class(['truncate text-sm', 'font-semibold text-text' => is_null($n->read_at), 'font-medium text-muted' => ! is_null($n->read_at)])>
                            {{ $d['title'] ?? 'Notifikasi' }}
                        </p>
                        @if (! empty($d['body']))
                            <p class="mt-0.5 text-xs text-muted">{{ \Illuminate\Support\Str::limit(strip_tags($d['body']), 90) }}</p>
                        @endif
                        <p class="mt-1 text-[11px] text-muted/80">{{ $n->created_at?->diffForHumans() }}</p>
                    </div>
                </button>
            @empty
                <div class="flex flex-col items-center justify-center px-4 py-12 text-center">
                    <div class="grid h-12 w-12 place-items-center rounded-2xl bg-border/60 text-muted">
                        <x-ui.icon name="bell" class="h-6 w-6" />
                    </div>
                    <p class="mt-3 text-sm text-muted">Belum ada notifikasi.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
