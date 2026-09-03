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
                                @php($titipan = $this->creditBalance())
                                @if (bccomp($titipan, '0', 2) > 0)
                                    {{-- Titipan Pokok (ADR 2026-08-28). Tagihan yang berubah-ubah bukan
                                         konsep yang bisa diturunkan petugas dari pengetahuan mereka —
                                         itu akibat keputusan desain, jadi sistem yang menjelaskannya. --}}
                                    <div class="mt-3 flex items-center justify-between border-t border-secondary/15 pt-3 text-sm">
                                        <span class="text-muted">Tagihan kontrak</span>
                                        <span class="tabular-nums text-muted">Rp {{ number_format((float) $schedule->total_due, 0, ',', '.') }}</span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-muted">Titipan Pokok dipakai</span>
                                        <span class="tabular-nums text-muted">&minus; Rp {{ number_format((float) bcsub((string) $schedule->total_due, $this->effectiveBill($schedule), 2), 0, ',', '.') }}</span>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between border-t border-secondary/15 pt-3 text-sm">
                                        <span class="font-semibold text-secondary">Tagihan Bulan Ini</span>
                                        <span class="font-bold tabular-nums text-text">Rp {{ number_format((float) $this->effectiveBill($schedule), 0, ',', '.') }}</span>
                                    </div>
                                    <p class="mt-2 text-[11px] leading-relaxed text-muted">
                                        Sisa Titipan Pokok anggota Rp {{ number_format((float) $titipan, 0, ',', '.') }}.
                                        Titipan hanya memotong <strong>pokok</strong>; jasa dan Tab. Berjangka tetap tertagih.
                                    </p>
                                @else
                                    <div class="mt-3 flex items-center justify-between border-t border-secondary/15 pt-3 text-sm">
                                        <span class="font-semibold text-secondary">Total Tagihan</span>
                                        <span class="font-bold tabular-nums text-text">Rp {{ number_format((float) $schedule->total_due, 0, ',', '.') }}</span>
                                    </div>
                                @endif
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

            {{-- Pelunasan Dipercepat (ADR 2026-07-22) --}}
            @if ($canSettleEarly)
                <x-ui.card @class(['ring-1 ring-inset ring-warning/25' => $settle_early])>
                    <label for="settle_early" class="flex cursor-pointer items-start gap-3">
                        <input id="settle_early" type="checkbox" wire:model.live="settle_early"
                               class="mt-0.5 h-4.5 w-4.5 rounded border-border text-warning focus:ring-warning">
                        <span>
                            <span class="block text-sm font-semibold text-text">Pelunasan Dipercepat</span>
                            <span class="mt-0.5 block text-xs text-muted">Lunasi <span class="font-medium text-text">seluruh sisa</span> pinjaman sekarang. Nasabah bayar sisa pokok + 1× jasa; jasa bulan berikutnya <span class="font-medium text-text">dibebaskan</span>. SWP &amp; Tab. Berjangka dikembalikan.</span>
                        </span>
                    </label>

                    @if ($settle_early && $settlementPreview)
                        <div class="mt-4 space-y-3 rounded-2xl border border-warning/20 bg-warning/5 p-4 text-sm">
                            <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-warning">
                                <x-ui.icon name="sparkles" class="h-3.5 w-3.5" /> Rincian Pelunasan
                            </p>
                            <div class="space-y-1.5 tabular-nums">
                                <div class="flex justify-between"><span class="text-muted">Sisa pokok</span><span class="font-medium text-text">Rp {{ number_format((float) $settlementPreview['settled_principal'], 0, ',', '.') }}</span></div>
                                <div class="flex justify-between"><span class="text-muted">Jasa (1×)</span><span class="font-medium text-text">Rp {{ number_format((float) $settlementPreview['interest'], 0, ',', '.') }}</span></div>
                                <div class="flex justify-between border-t border-warning/15 pt-1.5"><span class="font-semibold text-warning">Jumlah Pelunasan</span><span class="font-bold text-text">Rp {{ number_format((float) $settlementPreview['payoff'], 0, ',', '.') }}</span></div>
                            </div>
                            <div class="space-y-1.5 border-t border-warning/15 pt-2 tabular-nums">
                                <p class="text-xs text-muted">Dikembalikan ke anggota (draft):</p>
                                <div class="flex justify-between"><span class="text-muted">SWP</span><span class="font-medium text-text">Rp {{ number_format((float) $settlementPreview['refund_swp'], 0, ',', '.') }}</span></div>
                                <div class="flex justify-between"><span class="text-muted">Tab. Berjangka</span><span class="font-medium text-text">Rp {{ number_format((float) $settlementPreview['refund_tab'], 0, ',', '.') }}</span></div>
                                <div class="flex justify-between"><span class="font-semibold text-success">Total Refund</span><span class="font-bold text-success">Rp {{ number_format((float) $settlementPreview['refund_total'], 0, ',', '.') }}</span></div>
                            </div>
                            <p class="text-[11px] leading-relaxed text-muted">Isi nominal ≥ jumlah pelunasan. Kelebihan di atasnya masuk Simpanan Sukarela.</p>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Nominal pembayaran --}}
            @if ($schedule)
                <x-ui.card>
                    <div class="flex items-center gap-2.5 border-b border-border pb-3">
                        <span class="grid h-7 w-7 place-items-center rounded-lg bg-secondary/10 text-secondary">
                            <x-ui.icon name="banknotes" class="h-4 w-4" />
                        </span>
                        <h3 class="text-sm font-semibold text-text">Nominal Diterima</h3>
                    </div>
                    @if ($fromSavings)
                        <p class="mt-3 text-xs text-muted">Dibayar dengan mendebit <span class="font-medium text-text">Simpanan Sukarela</span> anggota. Nominal dikunci tepat sebesar tagihan — tidak boleh lebih.</p>
                    @else
                        <p class="mt-3 text-xs text-muted">Total uang yang benar-benar diterima. Sudah diisi sesuai tagihan; boleh dinaikkan, tidak boleh kurang dari tagihan. Kelebihan disimpan sebagai <span class="font-medium text-text">Titipan Pokok</span> dan memotong pokok angsuran berikutnya.</p>
                    @endif

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
                                       @readonly($fromSavings)
                                       @class([
                                           'h-10 w-full rounded-lg bg-transparent px-2 text-base font-semibold tabular-nums text-text focus-visible:outline-none',
                                           'cursor-not-allowed text-muted' => $fromSavings,
                                       ])>
                            </div>
                            @error('amount_paid')<p class="text-xs text-danger">{{ $message }}</p>
                            @elseif ($schedule)<p class="text-xs text-muted">Tagihan bulan ini: Rp {{ number_format((float) $schedule->total_due, 0, ',', '.') }}.</p>@enderror
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="payment_method" class="block text-sm font-medium text-text">Metode Bayar</label>
                            <select id="payment_method" wire:model.live="payment_method"
                                    class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                                @foreach ($paymentMethods as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                            @if ($fromSavings)
                                <p class="text-xs text-secondary">
                                    Saldo Sukarela: <span class="font-semibold tabular-nums">Rp {{ number_format((float) ($availableSukarela ?? 0), 0, ',', '.') }}</span>
                                    · nominal dikunci = tagihan, bukti persetujuan wajib.
                                </p>
                            @endif
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
                            <label for="bukti" class="block text-sm font-medium text-text">
                                {{ $fromSavings ? 'Bukti Persetujuan Anggota' : 'Bukti Pembayaran' }}
                                @if ($fromSavings)
                                    <span class="text-danger">(wajib)</span>
                                @else
                                    <span class="text-muted">(opsional)</span>
                                @endif
                            </label>
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
                                        <span class="text-xs text-muted">JPG, PNG, WebP, atau PDF · maks. 5 MB</span>
                                    @endif
                                </span>
                                <span wire:loading wire:target="bukti">
                                    <svg class="h-4 w-4 animate-spin text-muted" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                </span>
                                <input id="bukti" type="file" wire:model="bukti" accept=".jpg,.jpeg,.png,.webp,.pdf" class="hidden">
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
                        {{ $settle_early ? 'Pelunasan' : 'Tagihan' }} Rp <span x-text="rupiah(bill)"></span>
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
                            {{ $settle_early ? 'Lunaskan Sekarang' : 'Catat Pembayaran' }}
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

    {{-- Dialog alokasi kelebihan bayar (ADR 2026-08-28 item 2a).

         Akibat WAJIB ditampilkan dalam angka, bukan hanya nama pilihan. Dialog
         dua-tombol tanpa angka memang lebih murah dibuat — tapi mencabutnya
         berarti mencabut satu-satunya penjelasan yang petugas punya di depan
         anggota, sekaligus melemahkan pengaman yang jadi dasar penerimaan R14. --}}
    @if ($showAllocationDialog)
        @php($preview = $this->allocationPreview())
        @if ($preview)
            <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" wire:key="alloc-dialog">
                <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
                    {{-- Kepala berpembatas: memisahkan pertanyaan dari pilihan, dan
                         memberi dialog tepi yang terlihat di atas latar gelap. --}}
                    <div class="border-b border-border bg-bg/40 p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-text">
                            Uang diterima Rp {{ number_format((float) $amount_paid, 0, ',', '.') }} — melebihi tagihan bulan ini
                            (Rp {{ number_format((float) $this->effectiveBill(), 0, ',', '.') }}).
                        </h3>
                        <p class="mt-1 text-xs text-muted">Pilih bagaimana kelebihannya diperlakukan.</p>
                    </div>

                    <div class="space-y-3 p-6">
                        @foreach ([\App\Services\LoanPaymentService::MODE_TITIPAN, \App\Services\LoanPaymentService::MODE_TUTUP_SEKALIAN] as $option)
                            @php($p = $preview[$option])
                            {{-- Memilih = langsung menyimpan, tanpa klik kedua. Karena itu
                                 tombolnya HARUS menunjukkan bahwa ia sedang bekerja: tanpa
                                 penanda, jeda simpan terbaca sebagai klik yang tak masuk dan
                                 petugas menekannya lagi. --}}
                            <button type="button" wire:click="chooseMode('{{ $option }}')"
                                wire:loading.attr="disabled" wire:target="chooseMode"
                                class="w-full rounded-xl border border-border p-4 text-left transition hover:border-primary/50 hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:cursor-wait disabled:opacity-60">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-text">
                                        {{ \App\Services\LoanPaymentService::MODE_LABELS[$option] }}
                                    </span>
                                    <svg wire:loading wire:target="chooseMode('{{ $option }}')"
                                        class="h-3.5 w-3.5 animate-spin text-primary" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    @if ($option === \App\Services\LoanPaymentService::MODE_TITIPAN)
                                        <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">bawaan</span>
                                    @endif
                                </div>

                                <p class="mt-1.5 text-xs leading-relaxed text-muted">
                                    Angsuran
                                    <span class="font-medium text-text">
                                        #{{ implode(' dan #', $p['closed']) }}
                                    </span>
                                    lunas.
                                    @if (bccomp($p['credit_after'], '0', 2) > 0)
                                        Sisa Rp {{ number_format((float) $p['credit_after'], 0, ',', '.') }} memotong pokok bulan berikutnya:
                                        @foreach ($p['next'] as $next)
                                            angsuran #{{ $next['seq'] }} &rarr;
                                            <span class="font-medium text-text">Rp {{ number_format((float) $next['bill'], 0, ',', '.') }}</span>{{ $loop->last ? '.' : ',' }}
                                        @endforeach
                                    @else
                                        Tidak ada sisa.
                                        @if (count($p['next']) > 0)
                                            Bulan depan: angsuran #{{ $p['next'][0]['seq'] }},
                                            <span class="font-medium text-text">Rp {{ number_format((float) $p['next'][0]['bill'], 0, ',', '.') }}</span>.
                                        @endif
                                    @endif
                                </p>
                            </button>
                        @endforeach

                        {{-- Batal diberi warna: sebagai teks abu-abu ia terbaca sebagai
                             keterangan, bukan tombol, dan petugas yang ingin mundur tak
                             menemukan jalan keluarnya. --}}
                        <button type="button" wire:click="closeAllocationDialog"
                            wire:loading.attr="disabled" wire:target="chooseMode"
                            class="mt-1 w-full rounded-lg border border-danger/40 bg-danger/5 px-3 py-2.5 text-xs font-semibold text-danger transition hover:border-danger hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none disabled:opacity-50">
                            Batal
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- Dialog pilihan: melunasi atau tetap mencicil.
         Dulu keadaan ini ditolak mentah ("Gunakan Pelunasan Dipercepat").
         Penolakan itu melarang keadaan yang sah — anggota yang membawa uang
         lebih dan memang ingin pinjamannya berjalan terus — dan menyisakan
         petugas tanpa jalan selain menyuruhnya pulang. Sekarang ditawarkan,
         dengan akibat kedua pilihan tersaji dalam rupiah. --}}
    @if ($showSettlementChoice)
        @php($offer = $this->settlementOffer())
        @if ($offer)
            <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" wire:key="settle-choice">
                <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
                    <div class="border-b border-border bg-bg/40 p-6 shadow-sm">
                        <h3 class="text-base font-semibold text-text">
                            Uang diterima Rp {{ number_format((float) $offer['amount'], 0, ',', '.') }} — cukup untuk melunasi
                            seluruh sisa pinjaman.
                        </h3>
                        <p class="mt-1 text-xs text-muted">Pilih yang diminta anggota. Keduanya sah.</p>
                    </div>

                    <div class="space-y-3 p-6">
                        @if ($this->canSettleEarly())
                            <button type="button" wire:click="chooseSettlement"
                                wire:loading.attr="disabled" wire:target="chooseSettlement,chooseKeepInstalling"
                                class="w-full rounded-xl border border-border p-4 text-left transition hover:border-primary/50 hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:cursor-wait disabled:opacity-60">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-text">Pelunasan Dipercepat</span>
                                    <span class="rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-medium text-success">lebih ringan</span>
                                    <svg wire:loading wire:target="chooseSettlement" class="h-3.5 w-3.5 animate-spin text-primary"
                                        viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                </div>
                                <p class="mt-1.5 text-xs leading-relaxed text-muted">
                                    Bayar <span class="font-medium text-text">Rp {{ number_format((float) $offer['payoff'], 0, ',', '.') }}</span>,
                                    pinjaman <span class="font-medium text-text">LUNAS</span> hari ini.
                                    @if (bccomp($offer['jasa_dibebaskan'], '0', 2) > 0)
                                        Jasa {{ $offer['sisa_bulan'] - 1 }} bulan sisa dibebaskan —
                                        <span class="font-medium text-text">Rp {{ number_format((float) $offer['jasa_dibebaskan'], 0, ',', '.') }}</span>
                                        tak jadi ditagih.
                                    @endif
                                    @if (bccomp($offer['selisih'], '0', 2) > 0)
                                        Kembalian Rp {{ number_format((float) $offer['selisih'], 0, ',', '.') }} masuk Simpanan Sukarela.
                                    @endif
                                </p>
                            </button>
                        @endif

                        <button type="button" wire:click="chooseKeepInstalling"
                            wire:loading.attr="disabled" wire:target="chooseSettlement,chooseKeepInstalling"
                            class="w-full rounded-xl border border-border p-4 text-left transition hover:border-primary/50 hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:cursor-wait disabled:opacity-60">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-text">Tetap mencicil</span>
                                <svg wire:loading wire:target="chooseKeepInstalling" class="h-3.5 w-3.5 animate-spin text-primary"
                                    viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                            </div>
                            <p class="mt-1.5 text-xs leading-relaxed text-muted">
                                Angsuran bulan ini lunas, sisanya jadi
                                <span class="font-medium text-text">Titipan Pokok Rp {{ number_format((float) $offer['titipan_setelah'], 0, ',', '.') }}</span>
                                yang memotong pokok bulan-bulan berikutnya. Pinjaman tetap berjalan
                                @if (bccomp($offer['jasa_dibebaskan'], '0', 2) > 0)
                                    dan jasa {{ $offer['sisa_bulan'] - 1 }} bulan sisa
                                    (<span class="font-medium text-text">Rp {{ number_format((float) $offer['jasa_dibebaskan'], 0, ',', '.') }}</span>)
                                    <span class="font-medium text-text">tetap tertagih</span>.
                                @else
                                    .
                                @endif
                            </p>
                        </button>

                        <button type="button" wire:click="closeSettlementChoice"
                            wire:loading.attr="disabled" wire:target="chooseSettlement,chooseKeepInstalling"
                            class="mt-1 w-full rounded-lg border border-danger/40 bg-danger/5 px-3 py-2.5 text-xs font-semibold text-danger transition hover:border-danger hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none disabled:opacity-50">
                            Batal
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif

    <x-ui.toast-host />
</div>
