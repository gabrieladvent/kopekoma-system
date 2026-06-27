@php
    $typeColor = fn (string $type) => $type === 'jangka_panjang' ? 'primary' : 'warning';
@endphp

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="receipt-percent" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Pinjaman</h2>
                <p class="mt-0.5 text-sm text-muted">Daftar pinjaman anggota. Pinjaman immutable — koreksi salah-input lewat reversal.</p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @can('view_any_installment')
                <x-ui.button variant="ghost" :href="route('installments.create')" wire:navigate>
                    <x-ui.icon name="credit-card" class="h-4.5 w-4.5" />
                    Bayar Angsuran
                </x-ui.button>
            @endcan
            @can('create_loan')
                <x-ui.button :href="route('loans.create')" wire:navigate>
                    <x-ui.icon name="plus" class="h-4.5 w-4.5" />
                    Pinjaman Baru
                </x-ui.button>
            @endcan
        </div>
    </div>

    {{-- Bento stats --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="relative overflow-hidden rounded-2xl border border-border bg-linear-to-br from-primary/10 via-surface to-surface p-5 shadow-sm">
            <div class="absolute -right-4 -top-6 h-20 w-20 rounded-full bg-primary/10 blur-2xl" aria-hidden="true"></div>
            <div class="relative flex items-center gap-2 text-primary">
                <x-ui.icon name="arrow-trending-up" class="h-4 w-4" />
                <p class="text-xs font-semibold uppercase tracking-wide">Pinjaman Aktif</p>
            </div>
            <p class="relative mt-2 text-3xl font-bold tabular-nums text-text">{{ number_format($stats['active'], 0, ',', '.') }}</p>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="flex items-center gap-2 text-success">
                <x-ui.icon name="check" class="h-4 w-4" />
                <p class="text-xs font-semibold uppercase tracking-wide">Lunas</p>
            </div>
            <p class="mt-2 text-3xl font-bold tabular-nums text-text">{{ number_format($stats['settled'], 0, ',', '.') }}</p>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
            <div class="flex items-center gap-2 {{ $stats['overdue'] > 0 ? 'text-danger' : 'text-muted' }}">
                <x-ui.icon name="exclamation-triangle" class="h-4 w-4" />
                <p class="text-xs font-semibold uppercase tracking-wide">Tunggakan</p>
            </div>
            <p class="mt-2 text-3xl font-bold tabular-nums {{ $stats['overdue'] > 0 ? 'text-danger' : 'text-text' }}">
                {{ number_format($stats['overdue'], 0, ',', '.') }}
            </p>
        </div>

        <div class="relative overflow-hidden rounded-2xl border border-border bg-linear-to-br from-secondary/10 via-surface to-surface p-5 shadow-sm">
            <div class="absolute -right-4 -top-6 h-20 w-20 rounded-full bg-secondary/10 blur-2xl" aria-hidden="true"></div>
            <div class="relative flex items-center gap-2 text-secondary">
                <x-ui.icon name="wallet" class="h-4 w-4" />
                <p class="text-xs font-semibold uppercase tracking-wide">Sisa Pokok Aktif</p>
            </div>
            <p class="relative mt-2 text-2xl font-bold tabular-nums text-text">Rp {{ number_format((float) $stats['outstanding'], 0, ',', '.') }}</p>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative w-full lg:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari no. pinjaman atau anggota…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="type"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Jenis</option>
                @foreach ($loanTypes as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <select wire:model.live="status"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Status</option>
                <option value="Cair">Cair</option>
                <option value="Lunas">Lunas</option>
            </select>

            <select wire:model.live="arrears"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Angsuran</option>
                <option value="overdue">Ada Tunggakan</option>
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
                        <th class="px-5 py-3 text-left">No. Pinjaman</th>
                        <th class="px-5 py-3 text-left">Anggota</th>
                        <th class="px-5 py-3 text-left">Jenis</th>
                        <th class="px-5 py-3 text-right">Jumlah</th>
                        <th class="px-5 py-3 text-left">Status / Progres</th>
                        <th class="px-5 py-3 text-left">Pencairan</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 5; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-4 w-28 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-36 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-4 w-20 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-16 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-8 w-8 animate-pulse rounded-lg bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($loans as $loan)
                        <tr class="transition hover:bg-bg/60" wire:key="loan-{{ $loan->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('loans.show', $loan) }}" wire:navigate class="font-mono text-xs font-medium text-text hover:text-primary">
                                    {{ $loan->loan_number }}
                                </a>
                                @if ($loan->overdue_count > 0)
                                    <div class="mt-1"><x-ui.badge color="danger">{{ $loan->overdue_count }} terlewat</x-ui.badge></div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="text-text">{{ $loan->member?->full_name ?? '—' }}</span>
                                <p class="font-mono text-xs text-muted">{{ $loan->member?->member_number }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$typeColor($loan->loan_type)">{{ $loanTypes[$loan->loan_type] ?? $loan->loan_type }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-right font-medium tabular-nums text-text">
                                Rp {{ number_format((float) $loan->principal_amount, 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-4">
                                @if ($loan->status === 'Lunas')
                                    <x-ui.badge color="success"><x-ui.icon name="check" class="h-3 w-3" /> Lunas</x-ui.badge>
                                @else
                                    @php($pct = $loan->schedules_total > 0 ? (int) round($loan->schedules_paid / $loan->schedules_total * 100) : 0)
                                    <div class="w-32 space-y-1">
                                        <div class="flex items-center justify-between text-xs">
                                            {{-- <span class="font-medium {{ $loan->overdue_count > 0 ? 'text-danger' : 'text-primary' }}">Berjalan</span> --}}
                                            <span class="tabular-nums text-muted">{{ $loan->schedules_paid }}/{{ $loan->schedules_total }} angsuran</span>
                                        </div>
                                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-border/60">
                                            <div class="h-1.5 rounded-full {{ $loan->overdue_count > 0 ? 'bg-danger' : 'bg-linear-to-r from-primary to-secondary' }}" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-text">
                                {{ $loan->disbursement_date?->translatedFormat('d M Y') }}
                                <span class="block text-xs text-muted">{{ $loan->term_months }} bln</span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="eye" :href="route('loans.show', $loan)" wire:navigate>Lihat Detail</x-ui.dropdown-item>
                                        <x-ui.dropdown-item icon="printer" :href="route('loans.receipt', $loan)">Tanda Terima</x-ui.dropdown-item>

                                        @if ($this->canCorrect($loan))
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="arrow-uturn-left" variant="danger"
                                                wire:click="openCorrect('{{ $loan->id }}')">Koreksi Salah-Input</x-ui.dropdown-item>
                                        @endif
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary">
                                        <x-ui.icon name="receipt-percent" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $this->hasActiveFilters() ? 'Tidak ada pinjaman yang cocok' : 'Belum ada pinjaman' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $this->hasActiveFilters()
                                            ? 'Coba ubah kata kunci atau filter.'
                                            : 'Catat pinjaman anggota pertama yang sudah disetujui.' }}
                                    </p>
                                    @can('create_loan')
                                        @unless ($this->hasActiveFilters())
                                            <x-ui.button :href="route('loans.create')" wire:navigate class="mt-4 h-9 px-3">
                                                <x-ui.icon name="plus" class="h-4 w-4" /> Pinjaman Baru
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

        @if ($loans->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $loans->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Modal: Koreksi --}}
    <div x-data="{ show: @entangle('showCorrect').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
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
                    <x-ui.icon name="arrow-uturn-left" class="h-5 w-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-text">Koreksi Salah-Input</h3>
                    <p class="mt-1 text-xs text-muted">Hanya untuk pinjaman salah input yang belum punya angsuran. Record &amp; jadwalnya dihapus, dicatat di audit.</p>
                </div>
            </div>

            <form wire:submit="performCorrect" class="mt-5 space-y-4">
                <div class="space-y-1">
                    <label for="correctReason" class="block text-sm font-medium text-text">Alasan Koreksi</label>
                    <textarea id="correctReason" wire:model="correctReason" rows="3" placeholder="Wajib, minimal 5 karakter. Akan tercatat di log audit."
                              @class([
                                  'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                  'border-border' => ! $errors->has('correctReason'),
                                  'border-danger focus-visible:ring-danger' => $errors->has('correctReason'),
                              ])></textarea>
                    @error('correctReason')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="performCorrect">
                        <svg wire:loading wire:target="performCorrect" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Proses Koreksi
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>

    <x-ui.toast-host />
</div>
