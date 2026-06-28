<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-warning/10 text-warning">
                <x-ui.icon name="gift" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Pendaftaran Hari Raya</h2>
                <p class="mt-0.5 text-sm text-muted">Registrasi nominal simpanan Hari Raya per anggota tiap tahun program.</p>
            </div>
        </div>

        @can('create_member::holiday::saving')
            <x-ui.button :href="route('savings.holiday.create')" wire:navigate class="shrink-0">
                <x-ui.icon name="plus" class="h-4.5 w-4.5" />
                Daftarkan Anggota
            </x-ui.button>
        @endcan
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative w-full lg:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau no. anggota…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:items-center">
            <select wire:model.live="year"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua Tahun</option>
                @foreach ($yearOptions as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
            <select wire:model.live="active"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Status</option>
                <option value="1">Aktif</option>
                <option value="0">Non-Aktif</option>
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
                        <th class="px-5 py-3 text-left">Tahun</th>
                        <th class="px-5 py-3 text-left">Periode Pengumpulan</th>
                        <th class="px-5 py-3 text-right">Nominal Bulanan</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 5; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-4 w-40 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-5 w-14 animate-pulse rounded-full bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-44 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-5 w-16 animate-pulse rounded-full bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-8 w-8 animate-pulse rounded-lg bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($registrations as $registration)
                        <tr class="transition hover:bg-bg/60" wire:key="hol-{{ $registration->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('savings.holiday.show', $registration) }}" wire:navigate class="font-medium text-text hover:text-primary">
                                    {{ $registration->member?->full_name ?? '—' }}
                                </a>
                                <p class="font-mono text-xs text-muted">{{ $registration->member?->member_number }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge color="warning" class="font-mono">{{ $registration->period_year }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-text">
                                {{ $registration->start_date?->translatedFormat('d M Y') ?? '—' }}
                                <span class="text-muted">s/d</span>
                                {{ $registration->end_date?->translatedFormat('d M Y') ?? '—' }}
                            </td>
                            <td class="px-5 py-4 text-right font-medium tabular-nums text-text">
                                Rp {{ number_format((float) $registration->monthly_amount, 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$registration->is_active ? 'success' : 'neutral'">
                                    {{ $registration->is_active ? 'Aktif' : 'Non-Aktif' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="eye" :href="route('savings.holiday.show', $registration)" wire:navigate>Lihat Detail</x-ui.dropdown-item>

                                        @can('update_member::holiday::saving')
                                            <x-ui.dropdown-item icon="pencil" :href="route('savings.holiday.edit', $registration)" wire:navigate>Edit</x-ui.dropdown-item>
                                        @endcan

                                        @can('delete_member::holiday::saving')
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="trash" variant="danger"
                                                x-on:click="$dispatch('confirm-action', {
                                                    title: 'Hapus pendaftaran {{ $registration->member?->full_name }} ({{ $registration->period_year }})?',
                                                    message: 'Registrasi nominal Hari Raya ini akan dihapus. Setoran yang sudah tercatat tidak terpengaruh.',
                                                    confirmLabel: 'Hapus', variant: 'danger',
                                                    method: 'delete', params: [{{ $registration->id }}],
                                                })">Hapus</x-ui.dropdown-item>
                                        @endcan
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-warning/10 text-warning">
                                        <x-ui.icon name="gift" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $this->hasActiveFilters() ? 'Tidak ada pendaftaran yang cocok' : 'Belum ada pendaftaran Hari Raya' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $this->hasActiveFilters()
                                            ? 'Coba ubah kata kunci atau filter.'
                                            : 'Daftarkan anggota untuk menetapkan nominal simpanan Hari Raya tahun ini.' }}
                                    </p>
                                    @can('create_member::holiday::saving')
                                        @unless ($this->hasActiveFilters())
                                            <x-ui.button :href="route('savings.holiday.create')" wire:navigate class="mt-4 h-9 px-3">
                                                <x-ui.icon name="plus" class="h-4 w-4" /> Daftarkan Anggota
                                            </x-ui.button>
                                        @endunless
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($registrations->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $registrations->links() }}
            </div>
        @endif
    </x-ui.card>

    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
