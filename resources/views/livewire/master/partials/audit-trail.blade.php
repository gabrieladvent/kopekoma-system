{{--
    Partial audit trail untuk halaman detail (Golongan/OPD).
    Dirender dalam konteks komponen Livewire yang memakai trait
    App\Livewire\Concerns\InteractsWithAuditTrail.

    Variabel yang dibutuhkan:
    - $activities        : paginator activity
    - $selectedActivity  : activity terpilih untuk popup (nullable)
    - $diff              : array baris diff [label, old, new] dari $this->auditDiff()
--}}
<div>
    @if ($activities->isEmpty())
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-border/60 text-muted">
                <x-ui.icon name="clipboard" class="h-6 w-6" />
            </div>
            <p class="mt-3 text-sm text-muted">Belum ada aktivitas tercatat.</p>
        </div>
    @else
        <ol class="relative space-y-1 border-l border-border">
            @foreach ($activities as $activity)
                @php($color = $this->auditEventColor($activity->event))
                <li wire:key="act-{{ $activity->id }}" class="relative">
                    <button type="button" wire:click="viewAudit({{ $activity->id }})"
                            class="group flex w-full items-start gap-3 rounded-xl py-3 pl-6 pr-3 text-left transition hover:bg-bg/60 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <span @class([
                            'absolute left-0 top-4 h-2.5 w-2.5 -translate-x-1/2 rounded-full ring-4 ring-surface',
                            'bg-success' => $color === 'success',
                            'bg-warning' => $color === 'warning',
                            'bg-danger' => $color === 'danger',
                            'bg-primary' => $color === 'primary',
                            'bg-muted' => $color === 'neutral',
                        ])></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :color="$color">{{ $this->auditEventLabel($activity->event) }}</x-ui.badge>
                                <span class="text-xs text-muted">{{ $activity->created_at?->translatedFormat('d M Y H:i') }}</span>
                            </div>
                            @if ($activity->description)
                                <p class="mt-1 truncate text-sm text-text">{{ $activity->description }}</p>
                            @endif
                            <p class="mt-0.5 text-xs text-muted">oleh {{ $activity->causer?->name ?? 'Sistem' }}</p>
                        </div>
                        <x-ui.icon name="eye" class="mt-1 h-4 w-4 shrink-0 text-muted opacity-0 transition group-hover:opacity-100" />
                    </button>
                </li>
            @endforeach
        </ol>

        @if ($activities->hasPages())
            <div class="mt-4 border-t border-border pt-3">
                {{ $activities->links() }}
            </div>
        @endif
    @endif

    {{-- Popup detail aktivitas (diff Sebelum/Sesudah) --}}
    <div x-data="{ show: @entangle('showAudit').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @keydown.escape.window="show = false"
             role="dialog" aria-modal="true"
             class="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
            @if ($selectedActivity)
                <div class="flex items-start justify-between gap-4 border-b border-border px-6 py-4">
                    <div>
                        <x-ui.badge :color="$this->auditEventColor($selectedActivity->event)">{{ $this->auditEventLabel($selectedActivity->event) }}</x-ui.badge>
                        <h3 class="mt-2 text-base font-semibold tracking-tight text-text">Detail Aktivitas</h3>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ $selectedActivity->created_at?->translatedFormat('d M Y H:i:s') }}
                            · oleh {{ $selectedActivity->causer?->name ?? 'Sistem' }}
                        </p>
                    </div>
                    <button type="button" @click="show = false" aria-label="Tutup"
                            class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text">
                        <x-ui.icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                <div class="overflow-y-auto p-6">
                    @if ($selectedActivity->description)
                        <div class="mb-5">
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted">Deskripsi</dt>
                            <dd class="mt-1 text-sm text-text">{{ $selectedActivity->description }}</dd>
                        </div>
                    @endif

                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted">Perubahan Data</p>
                    @if (empty($diff))
                        <p class="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted">
                            Tidak ada perubahan kolom yang tercatat.
                        </p>
                    @else
                        <div class="overflow-hidden rounded-xl border border-border">
                            <table class="w-full text-sm">
                                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left">Kolom</th>
                                        <th class="px-4 py-2.5 text-left">Sebelum</th>
                                        <th class="px-4 py-2.5 text-left">Sesudah</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($diff as $row)
                                        <tr>
                                            <td class="px-4 py-2.5 font-medium text-text">{{ $row['label'] }}</td>
                                            <td class="px-4 py-2.5 text-muted line-through decoration-danger/40">{{ $row['old'] }}</td>
                                            <td class="px-4 py-2.5 font-medium text-text">{{ $row['new'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
