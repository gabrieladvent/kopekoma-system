<div class="mx-auto max-w-full space-y-6"
     x-data="{
        members: @js($includedMembers),
        loans: 0,
        total: 0,
        recompute() {
            let m = 0, l = 0, t = 0;
            this.$root.querySelectorAll('[data-member-row]').forEach(row => {
                if (! row.querySelector('[data-member-include]')?.checked) return;
                let counted = false;
                row.querySelectorAll('[data-line]').forEach(ln => {
                    if (! ln.querySelector('[data-line-include]')?.checked) return;
                    const inp = ln.querySelector('[data-amt]');
                    t += parseInt(String(inp ? inp.value : '0').replace(/\D/g, ''), 10) || 0;
                    l++; counted = true;
                });
                if (counted) m++;
            });
            this.members = m; this.loans = l; this.total = t;
        },
        rupiah(v) { return new Intl.NumberFormat('id-ID').format(v || 0); },
     }"
     x-init="$nextTick(() => recompute())"
     @input="recompute()"
     @change="recompute()"
     @rows-updated.window="$nextTick(() => recompute())">

    {{-- Back --}}
    <a href="{{ route('installments.index') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Hero header --}}
    <div class="relative overflow-hidden rounded-3xl border border-border bg-linear-to-br from-secondary/12 via-surface to-primary/8 px-6 py-7 sm:px-8">
        <div class="bg-grid pointer-events-none absolute inset-0 opacity-60" aria-hidden="true"></div>
        <div class="absolute -right-6 -top-8 h-32 w-32 rounded-full bg-secondary/10 blur-2xl" aria-hidden="true"></div>
        <div class="relative flex items-start gap-4">
            <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-secondary text-white shadow-lg shadow-secondary/25">
                <x-ui.icon name="users" class="h-7 w-7" />
            </span>
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-secondary">
                    <x-ui.icon name="sparkles" class="h-3 w-3" /> Mode Kolektif
                </span>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">Batch Potong Gaji Angsuran per OPD</h2>
                <p class="mt-1 max-w-lg text-sm text-muted">Tarik anggota satu OPD beserta pinjaman aktifnya, lalu catat pembayaran angsuran (jadwal terlama, FIFO) sekaligus dalam satu proses.</p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
        {{-- KIRI --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Card: OPD, periode & tanggal --}}
            <x-ui.card>
                <div class="flex items-center gap-2.5 border-b border-border pb-3">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-secondary/10 text-secondary">
                        <x-ui.icon name="building-office" class="h-4 w-4" />
                    </span>
                    <h3 class="text-sm font-semibold text-text">Pilih OPD, Periode &amp; Tanggal</h3>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <div class="space-y-1">
                        <label for="agency_id" class="block text-sm font-medium text-text">OPD / Instansi</label>
                        <select id="agency_id" wire:model.live="agency_id"
                                @class([
                                    'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                    'border-border' => ! $errors->has('agency_id'),
                                    'border-danger focus-visible:ring-danger' => $errors->has('agency_id'),
                                ])>
                            <option value="">— Pilih OPD —</option>
                            @foreach ($agencies as $agency)
                                <option value="{{ $agency->id }}">{{ $agency->agency_name }}</option>
                            @endforeach
                        </select>
                        @error('agency_id')<p class="text-xs text-danger">{{ $message }}</p>@enderror
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

                    <div class="space-y-1">
                        <label for="period_month" class="block text-sm font-medium text-text">Periode</label>
                        <input id="period_month" type="month" wire:model="period_month"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('period_month'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('period_month'),
                               ])>
                        @error('period_month')<p class="text-xs text-danger">{{ $message }}</p>
                        @else<p class="text-xs text-muted">Untuk pelabelan rekap/audit batch.</p>@enderror
                    </div>
                </div>
            </x-ui.card>

            {{-- Card: anggota --}}
            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                    <div class="flex items-center gap-2.5">
                        <span class="grid h-7 w-7 place-items-center rounded-lg bg-secondary/10 text-secondary">
                            <x-ui.icon name="users" class="h-4 w-4" />
                        </span>
                        <h3 class="text-sm font-semibold text-text">Anggota dengan Pinjaman Aktif</h3>
                    </div>
                    @if (! empty($rows))
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-muted"><span x-text="members">0</span> dari {{ $memberCount }} ikut</span>
                            <button type="button" wire:click="setAllIncluded(true)"
                                    class="rounded-lg px-2.5 py-1 text-xs font-medium text-secondary transition hover:bg-secondary/10">Pilih semua</button>
                            <button type="button" wire:click="setAllIncluded(false)"
                                    class="rounded-lg px-2.5 py-1 text-xs font-medium text-muted transition hover:bg-border/50">Batal semua</button>
                        </div>
                    @endif
                </div>

                @if (blank($agency_id))
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="grid h-16 w-16 place-items-center rounded-2xl bg-linear-to-br from-secondary/15 to-primary/10 text-secondary">
                            <x-ui.icon name="building-office" class="h-8 w-8" />
                        </div>
                        <h4 class="mt-4 text-sm font-semibold text-text">Pilih OPD dulu</h4>
                        <p class="mt-1 max-w-xs text-xs text-muted">Daftar anggota dengan pinjaman aktif beserta angsuran terlamanya akan muncul setelah OPD dipilih.</p>
                    </div>
                @elseif (empty($rows))
                    <div class="mt-4 rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-muted">
                        Tidak ada anggota <span class="font-medium text-text">Aktif</span> dengan pinjaman aktif di OPD ini.
                    </div>
                @else
                    <div class="mt-4 space-y-3" wire:loading.class="opacity-50" wire:target="agency_id">
                        @foreach ($rows as $i => $row)
                            <div data-member-row wire:key="row-{{ $row['member_id'] }}"
                                 x-data="{ on: @entangle('rows.'.$i.'.include') }"
                                 :class="on ? 'bg-surface' : 'bg-bg/40'"
                                 class="rounded-2xl border border-border transition">
                                {{-- Header anggota + checkbox ikut --}}
                                <label class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-semibold transition"
                                              :class="on ? 'bg-secondary/10 text-secondary' : 'bg-border/60 text-muted'">
                                            {{ \Illuminate\Support\Str::of(\Illuminate\Support\Str::after($row['member_label'], '— '))->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('') }}
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-text">{{ \Illuminate\Support\Str::after($row['member_label'], '— ') }}</span>
                                            <span class="font-mono text-xs text-muted">{{ \Illuminate\Support\Str::before($row['member_label'], ' —') }}</span>
                                        </span>
                                    </div>
                                    <span class="flex shrink-0 items-center gap-2.5">
                                        <span class="text-xs font-medium" :class="on ? 'text-secondary' : 'text-muted'" x-text="on ? 'Ikut' : 'Lewati'"></span>
                                        <input type="checkbox" data-member-include x-model="on"
                                               class="h-5 w-5 shrink-0 cursor-pointer rounded-md border-border accent-secondary focus-visible:ring-2 focus-visible:ring-secondary focus-visible:outline-none">
                                    </span>
                                </label>

                                {{-- Pinjaman aktif per anggota (tampil bila "Ikut") --}}
                                <div x-show="on" x-cloak class="space-y-3 border-t border-border px-4 py-3">
                                    @foreach ($row['lines'] as $j => $line)
                                        <div data-line wire:key="line-{{ $line['schedule_id'] }}"
                                             class="rounded-xl border border-border bg-surface px-3 py-3 transition has-checked:border-secondary/50 has-checked:bg-secondary/5">
                                            <div class="flex items-start justify-between gap-3">
                                                <label class="flex min-w-0 flex-1 cursor-pointer items-start gap-2.5">
                                                    <input type="checkbox" data-line-include wire:model="rows.{{ $i }}.lines.{{ $j }}.include"
                                                           class="mt-0.5 h-4.5 w-4.5 shrink-0 cursor-pointer rounded accent-secondary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                                                    <span class="min-w-0">
                                                        <span class="flex items-center gap-2 text-sm font-medium text-text">
                                                            <span class="font-mono text-xs">{{ $line['loan_number'] ?? '—' }}</span>
                                                            <span class="text-muted">·</span>
                                                            Angsuran #{{ $line['seq'] ?? '' }}
                                                        </span>
                                                        <span class="mt-0.5 block text-xs text-muted">
                                                            Jatuh tempo {{ $line['due_date'] ?? '—' }} · tagihan Rp {{ number_format((float) ($line['total_due'] ?? 0), 0, ',', '.') }}
                                                        </span>
                                                        <span class="mt-0.5 block text-xs text-muted">
                                                            Total pinjaman Rp {{ number_format((float) ($line['principal_amount'] ?? 0), 0, ',', '.') }} ·
                                                            sisa pokok Rp {{ number_format((float) ($line['remaining_principal'] ?? 0), 0, ',', '.') }}
                                                        </span>
                                                    </span>
                                                </label>

                                                {{-- Nominal — wire:ignore agar nilai input tak ter-reset morphdom
                                                     saat round-trip (mis. unggah bukti) sehingga total tak jadi 0. --}}
                                                <div class="shrink-0" wire:ignore
                                                     x-data="{
                                                        raw: @entangle('rows.'.$i.'.lines.'.$j.'.amount'),
                                                        display: '',
                                                        fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                                                        init() { this.display = this.fmt(this.raw); this.$watch('raw', (v) => { const f = this.fmt(v); if (f !== this.display) this.display = f; }); },
                                                        onInput(e) { const d = e.target.value.replace(/\D/g, ''); this.raw = d === '' ? null : parseInt(d, 10); this.display = this.fmt(d); },
                                                     }">
                                                    <div class="flex w-32 items-center rounded-lg border border-border bg-surface transition focus-within:ring-2 focus-within:ring-primary">
                                                        <span class="pl-2 text-xs text-muted">Rp</span>
                                                        <input type="text" inputmode="numeric" data-amt :value="display" @input="onInput($event)"
                                                               class="h-9 w-full rounded-lg bg-transparent px-1.5 text-sm font-semibold tabular-nums text-text focus-visible:outline-none">
                                                    </div>
                                                </div>
                                            </div>
                                            @error('rows.'.$i.'.lines.'.$j.'.amount')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror

                                            {{-- Pelunasan dipercepat per pinjaman (ADR 2026-07-22 5b) --}}
                                            @if ($canSettle && ($line['settleable'] ?? false))
                                                <label class="mt-2 flex cursor-pointer items-center gap-2 rounded-lg bg-warning/5 px-2.5 py-1.5 ring-1 ring-inset ring-warning/20">
                                                    <input type="checkbox" wire:model.live="rows.{{ $i }}.lines.{{ $j }}.settle_early"
                                                           class="h-4 w-4 shrink-0 rounded border-border accent-warning focus-visible:ring-2 focus-visible:ring-warning focus-visible:outline-none">
                                                    <span class="text-xs leading-tight">
                                                        <span class="font-semibold text-warning">Lunasi sekarang</span>
                                                        <span class="text-muted"> · pelunasan Rp {{ number_format((float) ($line['payoff'] ?? 0), 0, ',', '.') }}, jasa sisa dibebaskan</span>
                                                    </span>
                                                </label>
                                            @endif

                                            {{-- Bukti opsional per pinjaman --}}
                                            <div class="mt-3">
                                                <label for="bukti-{{ $line['schedule_id'] }}"
                                                       class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-dashed border-border px-3 py-2 transition hover:border-secondary/40 hover:bg-secondary/5">
                                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-secondary/10 text-secondary">
                                                        <x-ui.icon name="paper-clip" class="h-4 w-4" />
                                                    </span>
                                                    <span class="min-w-0 flex-1 text-xs">
                                                        @isset($bukti[$line['schedule_id']])
                                                            <span class="block truncate font-medium text-text">{{ $bukti[$line['schedule_id']]->getClientOriginalName() }}</span>
                                                            <span class="text-muted">Klik untuk ganti bukti</span>
                                                        @else
                                                            <span class="block font-medium text-text">Bukti pembayaran <span class="text-muted">(opsional)</span></span>
                                                            <span class="text-muted">JPG, PNG, WebP, atau PDF · maks. 5 MB</span>
                                                        @endisset
                                                    </span>
                                                    <span wire:loading wire:target="bukti.{{ $line['schedule_id'] }}">
                                                        <svg class="h-4 w-4 animate-spin text-muted" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                        </svg>
                                                    </span>
                                                    <input id="bukti-{{ $line['schedule_id'] }}" type="file"
                                                           wire:model="bukti.{{ $line['schedule_id'] }}"
                                                           accept=".jpg,.jpeg,.png,.webp,.pdf" class="hidden">
                                                </label>
                                                @error('bukti.'.$line['schedule_id'])<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- KANAN: ringkasan sticky --}}
        <div class="lg:sticky lg:top-24">
            <x-ui.card class="overflow-hidden p-0">
                <div class="relative overflow-hidden bg-linear-to-br from-secondary to-primary px-5 py-6 text-white">
                    <div class="absolute -right-4 -top-6 h-24 w-24 rounded-full bg-white/10 blur-xl" aria-hidden="true"></div>
                    <p class="relative text-xs font-medium uppercase tracking-wide text-white/80">Total Potong Gaji</p>
                    <p class="relative mt-1 text-3xl font-bold tabular-nums">Rp <span x-text="rupiah(total)">0</span></p>
                    <p class="relative mt-1 text-xs text-white/80">
                        <span x-text="members">0</span> anggota · <span x-text="loans">0</span> angsuran
                    </p>
                </div>

                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-bg/60 px-3 py-2.5 text-center">
                            <p class="text-lg font-bold tabular-nums text-text" x-text="members">0</p>
                            <p class="text-[11px] text-muted">Anggota ikut</p>
                        </div>
                        <div class="rounded-xl bg-bg/60 px-3 py-2.5 text-center">
                            <p class="text-lg font-bold tabular-nums text-text" x-text="loans">0</p>
                            <p class="text-[11px] text-muted">Angsuran</p>
                        </div>
                    </div>

                    @if ($settlementCount > 0)
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl bg-warning/5 px-3 py-2.5 ring-1 ring-inset ring-warning/25">
                            <input type="checkbox" wire:model.live="confirm_settlement"
                                   class="mt-0.5 h-4 w-4 shrink-0 rounded border-border accent-warning focus-visible:ring-2 focus-visible:ring-warning focus-visible:outline-none">
                            <span class="text-xs leading-relaxed text-warning">
                                <span class="font-semibold">{{ $settlementCount }} pinjaman akan DILUNASI</span> — jasa bulan sisa dibebaskan, SWP &amp; Tab. Berjangka dikembalikan. Saya paham &amp; konfirmasi.
                            </span>
                        </label>
                    @endif

                    <button type="button" wire:click="process" wire:loading.attr="disabled" wire:target="process"
                            @disabled(blank($agency_id) || empty($rows))
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-secondary px-4 text-sm font-semibold text-white shadow-sm shadow-secondary/25 transition hover:bg-secondary/90 focus-visible:ring-2 focus-visible:ring-secondary focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60">
                        <svg wire:loading wire:target="process" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <x-ui.icon wire:loading.remove wire:target="process" name="check" class="h-4.5 w-4.5" />
                        Proses Batch Angsuran
                    </button>

                    <p class="text-center text-[11px] leading-relaxed text-muted">
                        Metode <span class="font-medium text-text">Potong Gaji</span>. Hanya angsuran terlama (FIFO) tiap pinjaman; jadwal yang sudah terbayar otomatis dilewati. Kelebihan bayar dikreditkan ke Simpanan Sukarela.
                    </p>
                </div>
            </x-ui.card>
        </div>
    </div>

    <x-ui.toast-host />
</div>
