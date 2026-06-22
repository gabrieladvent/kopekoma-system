<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="shopping-cart" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Belanja Toko</h2>
                <p class="mt-0.5 text-sm text-muted">Pemakaian saldo Wajib Belanja anggota. Dana tidak dapat diuangkan.</p>
            </div>
        </div>

        @can('create_shopping::transaction')
            <x-ui.button :href="route('savings.shopping.create')" wire:navigate class="shrink-0">
                <x-ui.icon name="plus" class="h-4.5 w-4.5" />
                Catat Pemakaian
            </x-ui.button>
        @endcan
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative w-full lg:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari no. transaksi atau anggota…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="flex items-center gap-2">
            <select wire:model.live="reversal"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Transaksi</option>
                <option value="0">Pemakaian</option>
                <option value="1">Reversal</option>
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
                        <th class="px-5 py-3 text-left">No. Transaksi</th>
                        <th class="px-5 py-3 text-left">Anggota</th>
                        <th class="px-5 py-3 text-right">Nominal</th>
                        <th class="px-5 py-3 text-left">Tanggal</th>
                        <th class="px-5 py-3 text-left">Referensi</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 5; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-4 w-28 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-36 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-4 w-20 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-20 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-8 w-8 animate-pulse rounded-lg bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($transactions as $transaction)
                        <tr class="transition hover:bg-bg/60" wire:key="trx-{{ $transaction->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('savings.shopping.show', $transaction) }}" wire:navigate class="font-mono text-xs font-medium text-text hover:text-primary">
                                    {{ $transaction->transaction_number }}
                                </a>
                                @if ($transaction->is_reversal)
                                    <div class="mt-1"><x-ui.badge color="danger">Reversal</x-ui.badge></div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="text-text">{{ $transaction->member?->full_name ?? '—' }}</span>
                                <p class="font-mono text-xs text-muted">{{ $transaction->member?->member_number }}</p>
                            </td>
                            <td class="px-5 py-4 text-right font-medium tabular-nums {{ $transaction->is_reversal ? 'text-success' : 'text-danger' }}">
                                {{ $transaction->is_reversal ? '+' : '−' }}Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-4 text-text">{{ $transaction->transaction_date?->translatedFormat('d M Y') }}</td>
                            <td class="px-5 py-4 text-muted">{{ $transaction->reference_number ?: '—' }}</td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="eye" :href="route('savings.shopping.show', $transaction)" wire:navigate>Lihat Detail</x-ui.dropdown-item>

                                        @if ($this->canReverse($transaction))
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="arrow-uturn-left" variant="danger"
                                                wire:click="openReverse('{{ $transaction->id }}')">Reversal</x-ui.dropdown-item>
                                        @endif
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary">
                                        <x-ui.icon name="shopping-cart" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $this->hasActiveFilters() ? 'Tidak ada transaksi yang cocok' : 'Belum ada pemakaian belanja' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $this->hasActiveFilters()
                                            ? 'Coba ubah kata kunci atau filter.'
                                            : 'Catat pemakaian saldo Wajib Belanja anggota pertama.' }}
                                    </p>
                                    @can('create_shopping::transaction')
                                        @unless ($this->hasActiveFilters())
                                            <x-ui.button :href="route('savings.shopping.create')" wire:navigate class="mt-4 h-9 px-3">
                                                <x-ui.icon name="plus" class="h-4 w-4" /> Catat Pemakaian
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

        @if ($transactions->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $transactions->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Modal: Reversal --}}
    <div x-data="{ show: @entangle('showReverse').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
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
                    <h3 class="text-base font-semibold tracking-tight text-text">Reversal Pemakaian Belanja</h3>
                    <p class="mt-1 text-xs text-muted">Membuat transaksi-lawan; saldo Wajib Belanja kembali. Baris asli tidak dihapus.</p>
                </div>
            </div>

            <form wire:submit="performReverse" class="mt-5 space-y-4">
                <div class="space-y-1">
                    <label for="reverseReason" class="block text-sm font-medium text-text">Alasan Reversal</label>
                    <textarea id="reverseReason" wire:model="reverseReason" rows="3" placeholder="Wajib, minimal 5 karakter. Akan tercatat di log audit."
                              @class([
                                  'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                  'border-border' => ! $errors->has('reverseReason'),
                                  'border-danger focus-visible:ring-danger' => $errors->has('reverseReason'),
                              ])></textarea>
                    @error('reverseReason')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="performReverse">
                        <svg wire:loading wire:target="performReverse" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Proses Reversal
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>

    <x-ui.toast-host />
</div>
