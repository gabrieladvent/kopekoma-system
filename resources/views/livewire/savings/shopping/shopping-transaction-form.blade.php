<div class="mx-auto max-w-3xl space-y-6">
    {{-- Back --}}
    <a href="{{ route('savings.shopping') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
            <x-ui.icon name="shopping-cart" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">Catat Pemakaian Belanja</h2>
            <p class="mt-0.5 text-sm text-muted">Mencatat pemakaian saldo Wajib Belanja anggota (mengurangi saldo).</p>
        </div>
    </div>

    <form wire:submit="save">
        <x-ui.card>
            <div class="flex items-center gap-2 border-b border-border pb-3">
                <x-ui.icon name="receipt" class="h-5 w-5 text-primary" />
                <h3 class="text-sm font-semibold text-text">Pemakaian Saldo Wajib Belanja</h3>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                {{-- Anggota --}}
                <div class="sm:col-span-2">
                    @include('livewire.savings.partials.member-picker')
                </div>

                {{-- Saldo badge (muncul setelah pilih anggota) --}}
                @if ($balance !== null)
                    <div class="sm:col-span-2 flex items-center justify-between rounded-xl bg-primary/5 px-4 py-3 ring-1 ring-inset ring-primary/15">
                        <span class="flex items-center gap-2 text-sm font-medium text-primary">
                            <x-ui.icon name="wallet" class="h-4 w-4" /> Saldo Wajib Belanja
                        </span>
                        <span class="text-lg font-bold tabular-nums text-primary">Rp {{ number_format((float) $balance, 0, ',', '.') }}</span>
                    </div>
                @endif

                {{-- Nominal --}}
                <div>
                    <x-ui.money-input label="Nominal Pemakaian" model="amount" placeholder="50.000"
                        :error="$errors->first('amount')"
                        :hint="$balance === null ? 'Pilih anggota untuk melihat saldo Wajib Belanja.' : 'Tidak boleh melebihi saldo Wajib Belanja.'" />
                </div>

                {{-- Tanggal --}}
                <div class="space-y-1">
                    <label for="transaction_date" class="block text-sm font-medium text-text">Tanggal Pemakaian</label>
                    <input id="transaction_date" type="date" wire:model="transaction_date" max="{{ now()->toDateString() }}"
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                               'border-border' => ! $errors->has('transaction_date'),
                               'border-danger focus-visible:ring-danger' => $errors->has('transaction_date'),
                           ])>
                    @error('transaction_date')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>

                {{-- Referensi --}}
                <div>
                    <x-ui.input label="No. Referensi (opsional)" name="reference_number" wire:model="reference_number"
                        placeholder="Nomor nota / bukti belanja" :error="$errors->first('reference_number')"
                        hint="Nomor nota/bukti belanja bila ada." />
                </div>

                {{-- Catatan --}}
                <div class="space-y-1 sm:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-text">Catatan <span class="text-muted">(opsional)</span></label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Catatan tambahan…"
                              @class([
                                  'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                  'border-border' => ! $errors->has('notes'),
                                  'border-danger focus-visible:ring-danger' => $errors->has('notes'),
                              ])></textarea>
                    @error('notes')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-border pt-5">
                <x-ui.button type="button" variant="ghost" :href="route('savings.shopping')" wire:navigate>Batal</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    Catat Pemakaian
                </x-ui.button>
            </div>
        </x-ui.card>
    </form>

    <x-ui.toast-host />
</div>
