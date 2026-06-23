@php
    $tones = [
        'primary'   => ['ic' => 'bg-primary/10 text-primary',     'amt' => 'text-primary',   'accent' => 'accent-primary',   'card' => 'has-checked:border-primary/50 has-checked:bg-primary/5 hover:border-primary/40'],
        'secondary' => ['ic' => 'bg-secondary/10 text-secondary', 'amt' => 'text-secondary', 'accent' => 'accent-secondary', 'card' => 'has-checked:border-secondary/50 has-checked:bg-secondary/5 hover:border-secondary/40'],
        'success'   => ['ic' => 'bg-success/10 text-success',     'amt' => 'text-success',   'accent' => 'accent-success',   'card' => 'has-checked:border-success/50 has-checked:bg-success/5 hover:border-success/40'],
        'warning'   => ['ic' => 'bg-warning/10 text-warning',     'amt' => 'text-warning',   'accent' => 'accent-warning',   'card' => 'has-checked:border-warning/50 has-checked:bg-warning/5 hover:border-warning/40'],
        'muted'     => ['ic' => 'bg-muted/10 text-muted',         'amt' => 'text-muted',     'accent' => 'accent-muted',     'card' => 'has-checked:border-muted/50 has-checked:bg-muted/5 hover:border-muted/40'],
    ];
    $toneFor = fn (string $c) => $tones[match ($c) {
        'primary' => 'primary', 'info' => 'secondary', 'success' => 'success', 'warning' => 'warning', default => 'muted',
    }];
@endphp

<div class="mx-auto max-w-full space-y-6"
     x-data="{
        members: @js($includedMembers),
        deposits: 0,
        total: 0,
        recompute() {
            let m = 0, d = 0, t = 0;
            this.$root.querySelectorAll('[data-member-row]').forEach(row => {
                if (! row.querySelector('[data-member-include]')?.checked) return;
                let counted = false;
                row.querySelectorAll('[data-type]').forEach(tp => {
                    if (! tp.querySelector('[data-type-include]')?.checked) return;
                    const inp = tp.querySelector('[data-type-amount-input]');
                    const raw = inp ? inp.value : (tp.dataset.amountValue || '0');
                    t += parseInt(String(raw).replace(/\D/g, ''), 10) || 0;
                    d++; counted = true;
                });
                if (counted) m++;
            });
            this.members = m; this.deposits = d; this.total = t;
        },
        rupiah(v) { return new Intl.NumberFormat('id-ID').format(v || 0); },
     }"
     x-init="$nextTick(() => recompute())"
     @input="recompute()"
     @change="recompute()"
     @rows-updated.window="$nextTick(() => recompute())">

    {{-- Back --}}
    <a href="{{ route('savings.deposits') }}" wire:navigate
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
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">Batch Potong Gaji per OPD</h2>
                <p class="mt-1 max-w-lg text-sm text-muted">Tarik anggota aktif satu OPD, atur jenis simpanan &amp; nominalnya, lalu setor sekaligus dalam satu proses.</p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3 lg:items-start">
        {{-- KIRI --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Card: OPD & periode --}}
            <x-ui.card>
                <div class="flex items-center gap-2.5 border-b border-border pb-3">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-secondary/10 text-secondary">
                        <x-ui.icon name="building-office" class="h-4 w-4" />
                    </span>
                    <h3 class="text-sm font-semibold text-text">Pilih OPD &amp; Periode</h3>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
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
                        <label for="period_month" class="block text-sm font-medium text-text">Periode</label>
                        <input id="period_month" type="month" wire:model.live="period_month"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('period_month'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('period_month'),
                               ])>
                        @error('period_month')<p class="text-xs text-danger">{{ $message }}</p>
                        @else<p class="text-xs text-muted">Anggota yang sudah disetor periode ini dilewati otomatis.</p>@enderror
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
                        <h3 class="text-sm font-semibold text-text">Anggota Aktif</h3>
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
                        <p class="mt-1 max-w-xs text-xs text-muted">Daftar anggota aktif beserta jenis simpanannya akan muncul setelah OPD dipilih.</p>
                    </div>
                @elseif (empty($rows))
                    <div class="mt-4 rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-muted">
                        Tidak ada anggota berstatus <span class="font-medium text-text">Aktif</span> di OPD ini.
                    </div>
                @else
                    <div class="mt-4 space-y-3" wire:loading.class="opacity-50" wire:target="agency_id,period_month">
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

                                {{-- Jenis simpanan per anggota (tampil bila "Ikut") --}}
                                <div x-show="on" x-cloak class="grid gap-2 border-t border-border px-4 py-3 sm:grid-cols-2">
                                    @foreach ($row['lines'] as $j => $line)
                                        @php($type = $line['savings_type'])
                                        @php($done = $line['done'] ?? false)
                                        @php($locked = $this->isLocked($type))
                                        @php($t = $toneFor($this->typeColor($type)))

                                        @if ($done)
                                            {{-- Sudah disetor → terkunci --}}
                                            <div class="flex items-center justify-between gap-2 rounded-xl border border-dashed border-border bg-bg/50 px-3 py-2.5">
                                                <span class="flex items-center gap-2 text-sm text-muted">
                                                    <x-ui.icon name="check" class="h-4 w-4 text-success" />
                                                    {{ $line['type_label'] }}
                                                </span>
                                                <x-ui.badge color="success">Sudah disetor</x-ui.badge>
                                            </div>
                                        @else
                                            <div data-type @if($locked) data-amount-value="{{ (int) round((float) ($line['amount'] ?? 0)) }}" @endif
                                                 class="flex items-center gap-2.5 rounded-xl border border-border bg-surface px-3 py-2.5 transition {{ $t['card'] }}">
                                                {{-- Toggle jenis = checkbox biasa --}}
                                                <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5">
                                                    <input type="checkbox" data-type-include wire:model="rows.{{ $i }}.lines.{{ $j }}.include"
                                                           class="h-4.5 w-4.5 shrink-0 cursor-pointer rounded {{ $t['accent'] }} focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                                                    <span class="min-w-0">
                                                        <span class="block truncate text-sm font-medium text-text">{{ $line['type_label'] }}</span>
                                                        @if ($locked)
                                                            <span class="text-[11px] font-semibold tabular-nums {{ $t['amt'] }}">Rp {{ number_format((float) ($line['amount'] ?? 0), 0, ',', '.') }}</span>
                                                        @endif
                                                    </span>
                                                </label>

                                                @unless ($locked)
                                                    <div class="shrink-0"
                                                         x-data="{
                                                            raw: @entangle('rows.'.$i.'.lines.'.$j.'.amount'),
                                                            display: '',
                                                            fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                                                            init() { this.display = this.fmt(this.raw); this.$watch('raw', (v) => { const f = this.fmt(v); if (f !== this.display) this.display = f; }); },
                                                            onInput(e) { const d = e.target.value.replace(/\D/g, ''); this.raw = d === '' ? null : parseInt(d, 10); this.display = this.fmt(d); },
                                                         }">
                                                        <div class="flex w-28 items-center rounded-lg border border-border bg-surface transition focus-within:ring-2 focus-within:ring-primary">
                                                            <span class="pl-2 text-xs text-muted">Rp</span>
                                                            <input type="text" inputmode="numeric" data-type-amount-input :value="display" @input="onInput($event)"
                                                                   placeholder="0"
                                                                   class="h-9 w-full rounded-lg bg-transparent px-1.5 text-sm font-medium tabular-nums text-text placeholder:text-muted focus-visible:outline-none">
                                                        </div>
                                                    </div>
                                                @endunless
                                            </div>
                                        @endif
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
                        <span x-text="members">0</span> anggota · <span x-text="deposits">0</span> setoran
                    </p>
                </div>

                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-bg/60 px-3 py-2.5 text-center">
                            <p class="text-lg font-bold tabular-nums text-text" x-text="members">0</p>
                            <p class="text-[11px] text-muted">Anggota ikut</p>
                        </div>
                        <div class="rounded-xl bg-bg/60 px-3 py-2.5 text-center">
                            <p class="text-lg font-bold tabular-nums text-text" x-text="deposits">0</p>
                            <p class="text-[11px] text-muted">Setoran</p>
                        </div>
                    </div>

                    <button type="button" wire:click="process" wire:loading.attr="disabled" wire:target="process"
                            @disabled(blank($agency_id) || empty($rows))
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-secondary px-4 text-sm font-semibold text-white shadow-sm shadow-secondary/25 transition hover:bg-secondary/90 focus-visible:ring-2 focus-visible:ring-secondary focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60">
                        <svg wire:loading wire:target="process" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <x-ui.icon wire:loading.remove wire:target="process" name="check" class="h-4.5 w-4.5" />
                        Proses Batch
                    </button>

                    @if ($this->canExport())
                        @if (blank($agency_id))
                            <span class="inline-flex h-10 w-full cursor-not-allowed items-center justify-center gap-2 rounded-xl border border-border bg-surface px-4 text-sm font-medium text-muted opacity-60">
                                <x-ui.icon name="arrow-down-tray" class="h-4 w-4" />
                                Export Rekap (CSV)
                            </span>
                        @else
                            {{-- GET route → download CSV andal (bukan via aksi Livewire). --}}
                            <a href="{{ route('savings.deposits.batch.export', ['agency_id' => $agency_id, 'period_month' => $period_month]) }}"
                               class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-border bg-surface px-4 text-sm font-medium text-text transition hover:bg-bg/60 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                                <x-ui.icon name="arrow-down-tray" class="h-4 w-4" />
                                Export Rekap (CSV)
                            </a>
                        @endif
                    @endif

                    <p class="text-center text-[11px] leading-relaxed text-muted">
                        Metode <span class="font-medium text-text">Potong Gaji</span>, disetor oleh Bendahara. Jenis sudah disetor periode ini otomatis dilewati.
                    </p>
                </div>
            </x-ui.card>
        </div>
    </div>

    <x-ui.toast-host />
</div>
