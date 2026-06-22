@php($subjectLabel = fn ($type) => [
    'Member' => 'Anggota',
    'Grade' => 'Golongan',
    'Agency' => 'OPD / Instansi',
    'SavingsDeposit' => 'Setoran Simpanan',
    'SavingsWithdrawal' => 'Penarikan Simpanan',
    'ShoppingTransaction' => 'Transaksi Belanja',
    'MemberHolidaySaving' => 'Simpanan Hari Raya',
    'Loan' => 'Pinjaman',
    'Installment' => 'Angsuran',
    'StoreClient' => 'Store Client',
][class_basename($type ?? '')] ?? class_basename($type ?? '—'))
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
            <x-ui.icon name="bolt" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">Log Aktivitas</h2>
            <p class="mt-0.5 text-sm text-muted">Jejak audit seluruh perubahan data di sistem. Klik baris untuk melihat detail perubahan.</p>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="space-y-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="relative w-full lg:max-w-xs">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari deskripsi atau pelaku…"
                       class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
            </div>

            @if ($this->hasActiveFilters())
                <button type="button" wire:click="clearFilters"
                        class="inline-flex h-10 w-fit shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-danger transition hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none">
                    <x-ui.icon name="x" class="h-4 w-4" /> Bersihkan filter
                </button>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
            <select wire:model.live="event" class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua Event</option>
                @foreach ($eventOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="subject" class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua Objek</option>
                @foreach ($subjectOptions as $type)
                    <option value="{{ $type }}">{{ $subjectLabel($type) }}</option>
                @endforeach
            </select>
            <select wire:model.live="causer" class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua Pelaku</option>
                @foreach ($causerOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <input type="date" wire:model.live="from" title="Dari tanggal"
                   class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
            <input type="date" wire:model.live="until" title="Sampai tanggal"
                   class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-5 py-3 text-left">Waktu</th>
                        <th class="px-5 py-3 text-left">Event</th>
                        <th class="px-5 py-3 text-left">Objek</th>
                        <th class="px-5 py-3 text-left">Deskripsi</th>
                        <th class="px-5 py-3 text-left">Pelaku</th>
                        <th class="w-12 px-5 py-3 text-right"></th>
                    </tr>
                </thead>

                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 6; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-4 w-28 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-5 w-16 animate-pulse rounded-full bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-40 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($activities as $activity)
                        <tr class="cursor-pointer transition hover:bg-bg/60" wire:key="act-{{ $activity->id }}" wire:click="viewAudit({{ $activity->id }})">
                            <td class="whitespace-nowrap px-5 py-4 text-muted">{{ $activity->created_at?->translatedFormat('d M Y H:i') }}</td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$this->auditEventColor($activity->event)">{{ $this->auditEventLabel($activity->event) }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-text">{{ $subjectLabel($activity->subject_type) }}</td>
                            <td class="max-w-xs truncate px-5 py-4 text-text">{{ $activity->description ?: '—' }}</td>
                            <td class="px-5 py-4 text-muted">{{ $activity->causer?->name ?? 'Sistem' }}</td>
                            <td class="px-5 py-4 text-right">
                                <x-ui.icon name="eye" class="ml-auto h-4 w-4 text-muted" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary">
                                        <x-ui.icon name="bolt" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $this->hasActiveFilters() ? 'Tidak ada aktivitas yang cocok' : 'Belum ada aktivitas' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $this->hasActiveFilters() ? 'Coba ubah kata kunci atau filter.' : 'Aktivitas akan tercatat saat ada perubahan data.' }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($activities->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $activities->links() }}
            </div>
        @endif
    </x-ui.card>

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
                        <h3 class="mt-2 text-base font-semibold tracking-tight text-text">{{ $subjectLabel($selectedActivity->subject_type) }}</h3>
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
