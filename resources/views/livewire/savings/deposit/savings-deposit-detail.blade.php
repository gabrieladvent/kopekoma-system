@php
    $badgeColor = match ($savingsTypeColor) {
        'primary', 'info' => 'primary',
        'success' => 'success',
        'warning' => 'warning',
        default => 'neutral',
    };
@endphp

<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('savings.deposits') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-success/10 text-success">
                <x-ui.icon name="banknotes" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $deposit->transaction_number }}</x-ui.badge>
                    <x-ui.badge :color="$badgeColor">{{ $savingsTypeLabel }}</x-ui.badge>
                    @if ($deposit->is_reversal)
                        <x-ui.badge color="danger">Reversal</x-ui.badge>
                    @endif
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $deposit->member?->full_name ?? '—' }}</h2>
                <p class="font-mono text-sm text-muted">{{ $deposit->member?->member_number }}</p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <x-ui.button variant="ghost" :href="route('savings.deposits.slip', $deposit)">
                <x-ui.icon name="printer" class="h-4 w-4" /> Cetak Slip
            </x-ui.button>
            @if ($this->canReverse($deposit))
                <x-ui.button variant="danger" wire:click="openReverse">
                    <x-ui.icon name="arrow-uturn-left" class="h-4 w-4" /> Reversal
                </x-ui.button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                {{-- Nominal highlight --}}
                <div class="rounded-xl px-4 py-4 {{ $deposit->is_reversal ? 'bg-danger/5 ring-1 ring-inset ring-danger/15' : 'bg-success/5 ring-1 ring-inset ring-success/15' }}">
                    <p class="text-xs font-medium uppercase tracking-wide {{ $deposit->is_reversal ? 'text-danger' : 'text-success' }}">
                        {{ $deposit->is_reversal ? 'Nominal Dikoreksi' : 'Nominal Setoran' }}
                    </p>
                    <p class="mt-1 text-3xl font-bold tabular-nums {{ $deposit->is_reversal ? 'text-danger' : 'text-success' }}">
                        {{ $deposit->is_reversal ? '−' : '+' }}Rp {{ number_format((float) $deposit->amount, 0, ',', '.') }}
                    </p>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5">
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Tanggal Setor
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $deposit->deposit_date?->translatedFormat('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Periode
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $deposit->period_month?->translatedFormat('F Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="wallet" class="h-3.5 w-3.5" /> Metode Setor
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $depositMethodLabel }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="user" class="h-3.5 w-3.5" /> Disetor Oleh
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $depositedByLabel }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="hashtag" class="h-3.5 w-3.5" /> No. Referensi
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $deposit->reference_number ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="building-office" class="h-3.5 w-3.5" /> OPD / Instansi
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $deposit->member?->agency?->agency_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="user" class="h-3.5 w-3.5" /> Dicatat Oleh
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $deposit->recordedBy?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Dicatat Pada
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $deposit->created_at?->translatedFormat('d M Y · H.i') }} WIB</dd>
                    </div>
                    @if ($deposit->is_reversal && $deposit->reversalOf)
                        <div class="col-span-2 rounded-lg border border-dashed border-border px-3 py-2.5">
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted">Reversal Atas</dt>
                            <dd class="mt-1">
                                <a href="{{ route('savings.deposits.show', $deposit->reversalOf) }}" wire:navigate
                                   class="font-mono text-sm font-medium text-primary hover:underline">
                                    {{ $deposit->reversalOf->transaction_number }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    <div class="col-span-2">
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="document" class="h-3.5 w-3.5" /> Catatan
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $deposit->notes ?: '—' }}</dd>
                    </div>
                </dl>
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
                    <h3 class="text-base font-semibold tracking-tight text-text">Reversal Setoran</h3>
                    <p class="mt-1 text-xs text-muted">Membuat transaksi-lawan; saldo simpanan tersesuaikan. Baris asli tidak dihapus.</p>
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
