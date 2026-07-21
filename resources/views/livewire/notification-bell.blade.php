{{-- Bell notifikasi (database notifications Filament). Panel slide-over dari kanan. Poll 30s. --}}
<div x-data="{ open: false }" wire:poll.30s>
    <button type="button" @click="open = true" :aria-expanded="open" aria-label="Notifikasi"
            class="relative grid h-9 w-9 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/50 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        <x-ui.icon name="bell" class="h-5 w-5" />
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-none text-white ring-2 ring-surface">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Slide-over --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-60" role="dialog" aria-modal="true" aria-label="Panel notifikasi">
            {{-- Overlay --}}
            <div x-show="open" x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="open = false" class="absolute inset-0 bg-black/40 backdrop-blur-sm"></div>

            {{-- Panel --}}
            <div x-show="open"
                 x-transition:enter="transform transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transform transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                 @keydown.escape.window="open = false"
                 class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col border-l border-border bg-surface shadow-2xl">
                {{-- Header --}}
                <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                    <div class="flex items-center gap-2.5">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-primary/10 text-primary">
                            <x-ui.icon name="bell" class="h-5 w-5" />
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold text-text">Notifikasi</h3>
                            <p class="text-xs text-muted">{{ $unreadCount > 0 ? $unreadCount.' belum dibaca' : 'Semua sudah dibaca' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        @if ($unreadCount > 0)
                            <button type="button" wire:click="markAllAsRead"
                                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-primary transition hover:bg-primary/10">
                                <x-ui.icon name="check" class="h-3.5 w-3.5" /> Tandai semua
                            </button>
                        @endif
                        <button type="button" @click="open = false" aria-label="Tutup"
                                class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text">
                            <x-ui.icon name="x" class="h-5 w-5" />
                        </button>
                    </div>
                </div>

                {{-- List --}}
                <div class="flex-1 overflow-y-auto">
                    @forelse ($notifications as $n)
                        @php($d = $n->data)
                        @php($status = $d['status'] ?? 'info')
                        @php($tone = match ($status) {
                            'success' => ['dot' => 'bg-success', 'ic' => 'bg-success/10 text-success'],
                            'warning' => ['dot' => 'bg-warning', 'ic' => 'bg-warning/10 text-warning'],
                            'danger' => ['dot' => 'bg-danger', 'ic' => 'bg-danger/10 text-danger'],
                            default => ['dot' => 'bg-primary', 'ic' => 'bg-primary/10 text-primary'],
                        })
                        @php($icon = match ($status) {
                            'warning' => 'clock',
                            'danger' => 'exclamation-triangle',
                            'success' => 'check',
                            default => 'bell',
                        })
                        @php($hasLink = ! empty($d['actions'][0]['url']))
                        <button type="button" wire:click="open('{{ $n->id }}')" wire:key="notif-{{ $n->id }}"
                                @class([
                                    'flex w-full items-start gap-3 border-b border-border px-5 py-4 text-left transition hover:bg-bg/60',
                                    'bg-primary/5' => is_null($n->read_at),
                                ])>
                            <span class="relative mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl {{ $tone['ic'] }}">
                                <x-ui.icon :name="$icon" class="h-4.5 w-4.5" />
                                @if (is_null($n->read_at))
                                    <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full {{ $tone['dot'] }} ring-2 ring-surface"></span>
                                @endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <p @class(['text-sm', 'font-semibold text-text' => is_null($n->read_at), 'font-medium text-muted' => ! is_null($n->read_at)])>
                                    {{ $d['title'] ?? 'Notifikasi' }}
                                </p>
                                @if (! empty($d['body']))
                                    <p class="mt-0.5 text-xs leading-relaxed text-muted">{{ \Illuminate\Support\Str::limit(strip_tags($d['body']), 140) }}</p>
                                @endif
                                <div class="mt-1.5 flex items-center justify-between gap-2">
                                    <p class="text-[11px] text-muted/80">{{ $n->created_at?->diffForHumans() }}</p>
                                    @if ($hasLink)
                                        <span class="inline-flex items-center gap-0.5 text-[11px] font-medium text-primary">
                                            Lihat <x-ui.icon name="chevron-right" class="h-3 w-3" />
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </button>
                    @empty
                        <div class="flex flex-col items-center justify-center px-5 py-20 text-center">
                            <div class="grid h-14 w-14 place-items-center rounded-2xl bg-border/60 text-muted">
                                <x-ui.icon name="bell" class="h-7 w-7" />
                            </div>
                            <p class="mt-4 text-sm font-medium text-text">Belum ada notifikasi</p>
                            <p class="mt-1 max-w-xs text-xs text-muted">Pengingat angsuran &amp; aktivitas penting akan muncul di sini.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </template>
</div>
