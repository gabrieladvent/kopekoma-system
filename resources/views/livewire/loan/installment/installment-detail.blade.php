<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('installments.index') }}" wire:navigate
        class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-secondary/10 text-secondary">
                <x-ui.icon name="credit-card" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $installment->installment_number }}</x-ui.badge>
                    @if ($installment->is_reversal)
                        <x-ui.badge color="danger">Reversal</x-ui.badge>
                    @elseif ($installment->is_settlement)
                        <x-ui.badge color="warning">Pelunasan Dipercepat</x-ui.badge>
                    @else
                        <x-ui.badge color="success">Angsuran ke-{{ $installment->installment_seq }}</x-ui.badge>
                    @endif
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">
                    {{ $installment->loan?->member?->full_name ?? '—' }}</h2>
                <p class="font-mono text-sm text-muted">{{ $installment->loan?->member?->member_number }}</p>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <x-ui.button variant="ghost" :href="route('installments.receipt', $installment)">
                <x-ui.icon name="printer" class="h-4 w-4" /> Cetak Kuitansi
            </x-ui.button>
            @if ($this->canReverse($installment))
                <x-ui.button variant="danger" wire:click="openReverse">
                    <x-ui.icon name="arrow-uturn-left" class="h-4 w-4" /> Reversal
                </x-ui.button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                {{-- Total dibayar highlight --}}
                <div
                    class="rounded-xl px-4 py-4 {{ $installment->is_reversal ? 'bg-danger/5 ring-1 ring-inset ring-danger/15' : 'bg-success/5 ring-1 ring-inset ring-success/15' }}">
                    <p
                        class="text-xs font-medium uppercase tracking-wide {{ $installment->is_reversal ? 'text-danger' : 'text-success' }}">
                        {{ $installment->is_reversal ? 'Nominal Dibatalkan' : 'Total Dibayar' }}
                    </p>
                    <p
                        class="mt-1 text-3xl font-bold tabular-nums {{ $installment->is_reversal ? 'text-danger' : 'text-success' }}">
                        {{ $installment->is_reversal ? '−' : '+' }}Rp
                        {{ number_format((float) $installment->amount_paid, 0, ',', '.') }}
                    </p>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5">
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="banknotes" class="h-3.5 w-3.5" /> Pokok
                        </dt>
                        <dd class="mt-1 text-sm tabular-nums text-text">Rp
                            {{ number_format((float) $breakdown['principal'], 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="arrow-trending-up" class="h-3.5 w-3.5" /> Jasa
                        </dt>
                        <dd class="mt-1 text-sm tabular-nums text-text">Rp
                            {{ number_format((float) $breakdown['interest'], 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="wallet-stack" class="h-3.5 w-3.5" /> Tab. Berjangka
                        </dt>
                        <dd class="mt-1 text-sm tabular-nums text-text">Rp
                            {{ number_format((float) $breakdown['time_deposit'], 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="plus" class="h-3.5 w-3.5" /> Kelebihan Bayar
                        </dt>
                        <dd class="mt-1 text-sm tabular-nums text-text">Rp
                            {{ number_format((float) $breakdown['other'], 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="wallet" class="h-3.5 w-3.5" /> Sisa Pokok
                        </dt>
                        <dd class="mt-1 text-sm tabular-nums text-text">Rp
                            {{ number_format((float) $remaining, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Tgl Bayar
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $installment->payment_date?->translatedFormat('d M Y') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="clock" class="h-3.5 w-3.5" /> Jatuh Tempo
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ $installment->due_date?->translatedFormat('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="wallet" class="h-3.5 w-3.5" /> Metode Bayar
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $paymentMethodLabel }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="banknotes" class="h-3.5 w-3.5" /> Pinjaman
                        </dt>
                        <dd class="mt-1 text-sm">
                            @if ($installment->loan)
                                <a href="{{ route('loans.show', $installment->loan) }}" wire:navigate
                                    class="font-mono font-medium text-primary hover:underline">
                                    {{ $installment->loan->loan_number }}
                                </a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="user" class="h-3.5 w-3.5" /> Dicatat Oleh
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $installment->recordedBy?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Dicatat Pada
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ $installment->created_at?->translatedFormat('d M Y · H.i') }} WIB</dd>
                    </div>

                    @if ($installment->is_reversal && $installment->reversalOf)
                        <div class="col-span-2 rounded-lg border border-dashed border-border px-3 py-2.5">
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted">Reversal Atas</dt>
                            <dd class="mt-1">
                                <a href="{{ route('installments.show', $installment->reversalOf) }}" wire:navigate
                                    class="font-mono text-sm font-medium text-primary hover:underline">
                                    {{ $installment->reversalOf->installment_number }}
                                </a>
                            </dd>
                        </div>
                    @endif

                    @if ($installment->notes)
                        <div class="col-span-2">
                            <dt
                                class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="document" class="h-3.5 w-3.5" /> Catatan
                            </dt>
                            <dd class="mt-1 text-sm text-text">{{ $installment->notes }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($bukti)
                    @php
                        $buktiExt = strtolower(pathinfo((string) $bukti->file_name, PATHINFO_EXTENSION));
                        $buktiIsImage =
                            str_starts_with((string) $bukti->mime_type, 'image/') ||
                            in_array($buktiExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                        $buktiIsPdf = $bukti->mime_type === 'application/pdf' || $buktiExt === 'pdf';
                    @endphp
                    <div class="mt-5 border-t border-border pt-4">
                        <p
                            class="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="paper-clip" class="h-3.5 w-3.5" /> Bukti Pembayaran
                        </p>

                        @if ($buktiIsImage)
                            {{-- Gambar: tampil ukuran wajar; klik buka penuh di tab baru. --}}
                            <a href="{{ route('media.show', $bukti) }}" target="_blank" rel="noopener"
                                class="inline-block space-y-1.5">
                                <img src="{{ route('media.show', $bukti) }}"
                                    alt="Bukti pembayaran {{ $installment->installment_number }}"
                                    class="h-auto max-h-64 w-auto max-w-xs rounded-lg object-contain ring-1 ring-border transition hover:opacity-90">
                                <span class="flex items-center gap-1 text-xs text-muted">
                                    <x-ui.icon name="arrow-up-tray" class="h-3.5 w-3.5 rotate-45" />
                                    Klik untuk buka ukuran penuh di tab baru.
                                </span>
                            </a>
                        @elseif ($buktiIsPdf)
                            {{-- PDF: buka di tab baru (browser merender PDF natif). --}}
                            <a href="{{ route('media.show', $bukti) }}" target="_blank" rel="noopener"
                                class="inline-flex items-center gap-2 rounded-lg bg-secondary px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-secondary/90">
                                <x-ui.icon name="document" class="h-4 w-4 shrink-0" />
                                Buka bukti (PDF) di tab baru
                            </a>
                        @else
                            <a href="{{ route('media.show', $bukti) }}" target="_blank" rel="noopener"
                                class="inline-flex items-center gap-2 rounded-lg border border-border bg-bg/40 px-3 py-2 text-sm text-text transition hover:border-primary/40 hover:text-primary">
                                <x-ui.icon name="document" class="h-4 w-4 shrink-0" />
                                <span class="max-w-[14rem] truncate">{{ $bukti->name }}</span>
                            </a>
                        @endif
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

    {{-- Modal: Reversal --}}
    <div x-data="{ show: @entangle('showReverse').live }" x-show="show" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95" @keydown.escape.window="show = false" role="dialog"
            aria-modal="true"
            class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-danger/10 text-danger">
                    <x-ui.icon name="arrow-uturn-left" class="h-5 w-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-text">Reversal Pembayaran</h3>
                    <p class="mt-1 text-xs text-muted">Jadwal kembali Belum Bayar. Jika ini pelunasan, pengembalian SWP
                        &amp; Tab. Berjangka ikut dibatalkan.</p>
                </div>
            </div>

            <form wire:submit="performReverse" class="mt-5 space-y-4">
                <div class="space-y-1">
                    <label for="reverseReason" class="block text-sm font-medium text-text">Alasan Reversal</label>
                    <textarea id="reverseReason" wire:model="reverseReason" rows="3"
                        placeholder="Wajib, minimal 5 karakter. Akan tercatat di log audit." @class([
                            'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                            'border-border' => !$errors->has('reverseReason'),
                            'border-danger focus-visible:ring-danger' => $errors->has('reverseReason'),
                        ])></textarea>
                    @error('reverseReason')
                        <p class="text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled"
                        wire:target="performReverse">
                        <svg wire:loading wire:target="performReverse" class="h-4 w-4 animate-spin" fill="none"
                            viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z">
                            </path>
                        </svg>
                        Proses Reversal
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>

    <x-ui.toast-host />
</div>
