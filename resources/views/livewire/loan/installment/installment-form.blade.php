<div class="mx-auto max-w-full space-y-6"
     x-data="{
        bill: @js($schedule ? (int) round((float) $schedule->total_due) : 0),
        total: @js($totalPaid),
        recompute() {
            let sum = 0;
            this.$root.querySelectorAll('[data-amt]').forEach(inp => {
                sum += parseInt(String(inp.value).replace(/\D/g, ''), 10) || 0;
            });
            this.total = sum;
        },
        rupiah(v) { return new Intl.NumberFormat('id-ID').format(v || 0); },
     }"
     x-init="$nextTick(() => recompute())"
     @input="recompute()"
     @amounts-updated.window="total = $event.detail.total; bill = $event.detail.bill">

    {{-- Back --}}
    <a href="{{ route('installments.index') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Hero --}}
    <div class="relative overflow-hidden rounded-3xl border border-border bg-linear-to-br from-secondary/12 via-surface to-primary/8 px-6 py-7 sm:px-8">
        <div class="bg-grid pointer-events-none absolute inset-0 opacity-60" aria-hidden="true"></div>
        <div class="absolute -right-6 -top-8 h-32 w-32 rounded-full bg-secondary/10 blur-2xl" aria-hidden="true"></div>
        <div class="relative flex items-start gap-4">
            <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-secondary text-white shadow-lg shadow-secondary/25">
                <x-ui.icon name="credit-card" class="h-7 w-7" />
            </span>
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-secondary">
                    <x-ui.icon name="sparkles" class="h-3 w-3" /> Pembayaran
                </span>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">Bayar Angsuran</h2>
                <p class="mt-1 max-w-md text-sm text-muted">Pilih pinjaman aktif lalu masukkan nominal yang benar-benar diterima. Angsuran dibayar berurutan (FIFO).</p>
            </div>
        </div>
    </div>

    <form wire:submit="pay" class="grid gap-6 lg:grid-cols-3 lg:items-start">
        {{-- KIRI --}}
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                <div class="flex items-center gap-2.5 border-b border-border pb-3">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-secondary/10 text-secondary">
                        <x-ui.icon name="user" class="h-4 w-4" />
                    </span>
                    <h3 class="text-sm font-semibold text-text">Pinjaman &amp; Angsuran</h3>
                </div>

                <div class="mt-5 space-y-5">
                    @include('livewire.savings.partials.member-picker', ['label' => 'Anggota'])

                    {{-- Pilih pinjaman aktif --}}
                    @if (filled($member_id))
                        <div class="space-y-1">
                            <label for="loan_id" class="block text-sm font-medium text-text">Pinjaman Aktif</label>
                            @if (empty($loanOptions))
                                <div class="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted">
                                    Anggota ini tidak punya pinjaman aktif (status Cair).
                                </div>
                            @else
                                <select id="loan_id" wire:model.live="loan_id"
                                        @class([
                                            'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                            'border-border' => ! $errors->has('loan_id'),
                                            'border-danger focus-visible:ring-danger' => $errors->has('loan_id'),
                                        ])>
                                    <option value="">— Pilih pinjaman —</option>
                                    @foreach ($loanOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif
                            @error('loan_id')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    {{-- Jadwal terpilih --}}
                    @if (filled($loan_id))
                        @if ($schedule)
                            <div class="rounded-2xl border border-secondary/20 bg-secondary/5 p-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2 text-sm font-semibold text-secondary">
                                        <x-ui.icon name="clock" class="h-4 w-4" />
                                        Angsuran #{{ $schedule->installment_seq }}
                                    </div>
                                    @if ($isFinal)
                                        <x-ui.badge color="warning">Pelunasan</x-ui.badge>
                                    @endif
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                    <div>
                                        <p class="text-xs text-muted">Jatuh Tempo</p>
                                        <p class="mt-0.5 font-medium text-text">{{ $schedule->due_date?->translatedFormat('d M Y') }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted">Pokok</p>
                                        <p class="mt-0.5 font-medium tabular-nums text-text">Rp {{ number_format((float) $schedule->loan->monthly_principal, 0, ',', '.') }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted">Jasa</p>
                                        <p class="mt-0.5 font-medium tabular-nums text-text">Rp {{ number_format((float) $schedule->loan->monthly_interest, 0, ',', '.') }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted">Tab. Berjangka</p>
                                        <p class="mt-0.5 font-medium tabular-nums text-text">Rp {{ number_format((float) $schedule->loan->monthly_time_deposit, 0, ',', '.') }}</p>
                                    </div>
                                </div>
                            </div>
                            @error('schedule_id')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        @else
                            <div class="rounded-lg border border-dashed border-success/30 bg-success/5 px-4 py-6 text-center text-sm text-success">
                                Semua angsuran pinjaman ini sudah terbayar.
                            </div>
                        @endif
                    @endif
                </div>
            </x-ui.card>

            {{-- Nominal pembayaran --}}
            @if ($schedule)
                <x-ui.card>
                    <div class="flex items-center gap-2.5 border-b border-border pb-3">
                        <span class="grid h-7 w-7 place-items-center rounded-lg bg-secondary/10 text-secondary">
                            <x-ui.icon name="banknotes" class="h-4 w-4" />
                        </span>
                        <h3 class="text-sm font-semibold text-text">Nominal Diterima</h3>
                    </div>
                    <p class="mt-3 text-xs text-muted">Total uang yang benar-benar diterima. Sudah diisi sesuai tagihan; boleh dinaikkan, tidak boleh kurang dari tagihan. Kelebihan jadi <span class="font-medium text-text">Kelebihan Bayar</span> (dikreditkan ke Simpanan Sukarela).</p>

                    <div class="mt-4">
                        <div class="space-y-1"
                             x-data="{
                                raw: @entangle('amount_paid'),
                                display: '',
                                fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                                init() { this.display = this.fmt(this.raw); this.$watch('raw', (v) => { const f = this.fmt(v); if (f !== this.display) this.display = f; }); },
                                onInput(e) { const d = e.target.value.replace(/\D/g, ''); this.raw = d === '' ? null : parseInt(d, 10); this.display = this.fmt(d); },
                             }">
                            <label for="amount_paid" class="block text-sm font-medium text-text">Nominal Dibayar</label>
                            <div @class([
                                    'flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary',
                                    'border-border' => ! $errors->has('amount_paid'),
                                    'border-danger focus-within:ring-danger' => $errors->has('amount_paid'),
                                 ])>
                                <span class="pl-3 text-sm text-muted">Rp</span>
                                <input id="amount_paid" type="text" inputmode="numeric" data-amt :value="display" @input="onInput($event)"
                                       class="h-10 w-full rounded-lg bg-transparent px-2 text-base font-semibold tabular-nums text-text focus-visible:outline-none">
                            </div>
                            @error('amount_paid')<p class="text-xs text-danger">{{ $message }}</p>
                            @elseif ($schedule)<p class="text-xs text-muted">Tagihan bulan ini: Rp {{ number_format((float) $schedule->total_due, 0, ',', '.') }}.</p>@enderror
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="payment_method" class="block text-sm font-medium text-text">Metode Bayar</label>
                            <select id="payment_method" wire:model="payment_method"
                                    class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                                @foreach ($paymentMethods as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        <div class="space-y-1">
                            <label for="payment_date" class="block text-sm font-medium text-text">Tanggal Bayar</label>
                            <input id="payment_date" type="date" wire:model="payment_date" max="{{ now()->toDateString() }}"
                                   @class([
                                       'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                       'border-border' => ! $errors->has('payment_date'),
                                       'border-danger focus-visible:ring-danger' => $errors->has('payment_date'),
                                   ])>
                            @error('payment_date')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        @if ($isFinal)
                            <div class="space-y-1 sm:col-span-2 rounded-lg bg-secondary/5 px-3 py-2 ring-1 ring-inset ring-secondary/15">
                                <p class="text-xs text-muted">Ini angsuran pelunasan — SWP + Tab. Berjangka otomatis dikembalikan (draft) memakai metode pencairan yang ditetapkan saat akad pinjaman.</p>
                            </div>
                        @endif

                        {{-- Bukti --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="bukti" class="block text-sm font-medium text-text">Bukti Pembayaran <span class="text-muted">(opsional)</span></label>
                            <label for="bukti"
                                   class="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-border px-4 py-3 transition hover:border-secondary/40 hover:bg-secondary/5">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-secondary/10 text-secondary">
                                    <x-ui.icon name="arrow-up-tray" class="h-4.5 w-4.5" />
                                </span>
                                <span class="min-w-0 flex-1 text-sm">
                                    @if ($bukti)
                                        <span class="block truncate font-medium text-text">{{ $bukti->getClientOriginalName() }}</span>
                                        <span class="text-xs text-muted">Klik untuk ganti</span>
                                    @else
                                        <span class="block font-medium text-text">Slip / foto / kuitansi</span>
                                        <span class="text-xs text-muted">JPG atau PNG · maks. 5 MB</span>
                                    @endif
                                </span>
                                <span wire:loading wire:target="bukti">
                                    <svg class="h-4 w-4 animate-spin text-muted" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                </span>
                                <input id="bukti" type="file" wire:model="bukti" accept=".jpg,.jpeg,.png" class="hidden">
                            </label>
                            @error('bukti')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </x-ui.card>
            @endif
        </div>

        {{-- KANAN: ringkasan --}}
        <div class="lg:sticky lg:top-24">
            <x-ui.card class="overflow-hidden p-0">
                <div class="relative overflow-hidden bg-linear-to-br from-secondary to-primary px-5 py-6 text-white">
                    <div class="absolute -right-4 -top-6 h-24 w-24 rounded-full bg-white/10 blur-xl" aria-hidden="true"></div>
                    <p class="relative text-xs font-medium uppercase tracking-wide text-white/80">Total Dibayar</p>
                    <p class="relative mt-1 text-3xl font-bold tabular-nums">Rp <span x-text="rupiah(total)">0</span></p>
                    <p class="relative mt-1 text-xs text-white/80" x-show="bill > 0">
                        Tagihan Rp <span x-text="rupiah(bill)"></span>
                        <span x-show="total > bill" class="font-semibold"> · lebih Rp <span x-text="rupiah(total - bill)"></span></span>
                    </p>
                </div>

                <div class="space-y-4 p-5">
                    @if (filled($member_id) && $selectedMemberLabel)
                        <div class="flex items-center gap-2.5 rounded-xl bg-bg/60 px-3 py-2.5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary/10 text-secondary">
                                <x-ui.icon name="user" class="h-4 w-4" />
                            </span>
                            <span class="truncate text-sm font-medium text-text">{{ $selectedMemberLabel }}</span>
                        </div>
                    @endif

                    @if ($schedule)
                        <div class="space-y-1.5 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-muted">Angsuran ke</span>
                                <span class="font-medium text-text">#{{ $schedule->installment_seq }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted">Jatuh tempo</span>
                                <span class="font-medium text-text">{{ $schedule->due_date?->translatedFormat('d M Y') }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted">Pinjaman</span>
                                <span class="font-mono text-xs font-medium text-text">{{ $schedule->loan->loan_number }}</span>
                            </div>
                        </div>

                        @if ($isFinal)
                            <div class="flex items-start gap-2 rounded-xl bg-warning/5 px-3 py-2.5 text-xs text-warning ring-1 ring-inset ring-warning/15">
                                <x-ui.icon name="sparkles" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>Angsuran terakhir — menyelesaikan pinjaman ini akan mengembalikan SWP &amp; Tabungan Berjangka.</span>
                            </div>
                        @endif

                        <button type="submit" wire:loading.attr="disabled" wire:target="pay"
                                class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-secondary px-4 text-sm font-semibold text-white shadow-sm shadow-secondary/25 transition hover:bg-secondary/90 focus-visible:ring-2 focus-visible:ring-secondary focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60">
                            <svg wire:loading wire:target="pay" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            <x-ui.icon wire:loading.remove wire:target="pay" name="check" class="h-4.5 w-4.5" />
                            Catat Pembayaran
                        </button>
                    @else
                        <div class="rounded-xl border border-dashed border-border px-3 py-6 text-center text-xs text-muted">
                            Pilih anggota &amp; pinjaman aktif untuk mulai mencatat angsuran.
                        </div>
                    @endif

                    <x-ui.button type="button" variant="ghost" :href="route('installments.index')" wire:navigate class="w-full">
                        Batal
                    </x-ui.button>

                    <p class="text-center text-[11px] leading-relaxed text-muted">
                        Nominal aktual dipakai untuk saldo &amp; laporan. Koreksi hanya lewat <span class="font-medium text-text">reversal</span>.
                    </p>
                </div>
            </x-ui.card>
        </div>
    </form>

    <x-ui.toast-host />
</div>
