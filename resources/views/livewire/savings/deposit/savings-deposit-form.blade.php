@php
    // typeColor resource (primary/info/success/warning/gray) → kelas literal per nada
    // (literal agar terdeteksi scanner Tailwind v4; tak ada warna `info` → secondary).
    $tones = [
        'primary'   => ['ic' => 'bg-primary/10 text-primary',     'amt' => 'text-primary',   'dot' => 'group-has-checked:border-primary group-has-checked:bg-primary',     'card' => 'has-checked:border-primary/50 has-checked:bg-primary/5 has-checked:ring-primary/10 hover:border-primary/40'],
        'secondary' => ['ic' => 'bg-secondary/10 text-secondary', 'amt' => 'text-secondary', 'dot' => 'group-has-checked:border-secondary group-has-checked:bg-secondary', 'card' => 'has-checked:border-secondary/50 has-checked:bg-secondary/5 has-checked:ring-secondary/10 hover:border-secondary/40'],
        'success'   => ['ic' => 'bg-success/10 text-success',     'amt' => 'text-success',   'dot' => 'group-has-checked:border-success group-has-checked:bg-success',     'card' => 'has-checked:border-success/50 has-checked:bg-success/5 has-checked:ring-success/10 hover:border-success/40'],
        'warning'   => ['ic' => 'bg-warning/10 text-warning',     'amt' => 'text-warning',   'dot' => 'group-has-checked:border-warning group-has-checked:bg-warning',     'card' => 'has-checked:border-warning/50 has-checked:bg-warning/5 has-checked:ring-warning/10 hover:border-warning/40'],
        'muted'     => ['ic' => 'bg-muted/10 text-muted',         'amt' => 'text-muted',     'dot' => 'group-has-checked:border-muted group-has-checked:bg-muted',         'card' => 'has-checked:border-muted/50 has-checked:bg-muted/5 has-checked:ring-muted/10 hover:border-muted/40'],
    ];
    $toneFor = fn (string $c) => $tones[match ($c) {
        'primary' => 'primary',
        'info' => 'secondary',
        'success' => 'success',
        'warning' => 'warning',
        default => 'muted',
    }];
@endphp

<div class="mx-auto max-w-full space-y-6"
     x-data="{
        total: @js($total),
        count: @js($includedCount),
        items: [],
        recompute() {
            let sum = 0, items = [];
            this.$root.querySelectorAll('[data-line]').forEach(row => {
                const on = row.querySelector('[data-include]')?.checked;
                if (! on) return;
                const inp = row.querySelector('[data-amount-input]');
                const raw = inp ? inp.value : (row.dataset.amountValue || '0');
                const amt = parseInt(String(raw).replace(/\D/g, ''), 10) || 0;
                sum += amt;
                items.push({ label: row.dataset.label, amount: amt });
            });
            this.total = sum;
            this.count = items.length;
            this.items = items;
        },
        rupiah(v) { return new Intl.NumberFormat('id-ID').format(v || 0); },
     }"
     x-init="$nextTick(() => recompute())"
     @input="recompute()"
     @change="recompute()"
     @lines-updated.window="$nextTick(() => recompute())">

    {{-- Back --}}
    <a href="{{ route('savings.deposits') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Hero header --}}
    <div class="relative overflow-hidden rounded-3xl border border-border bg-linear-to-br from-success/12 via-surface to-primary/8 px-6 py-7 sm:px-8">
        <div class="bg-grid pointer-events-none absolute inset-0 opacity-60" aria-hidden="true"></div>
        <div class="absolute -right-6 -top-8 h-32 w-32 rounded-full bg-success/10 blur-2xl" aria-hidden="true"></div>
        <div class="relative flex items-start gap-4">
            <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-success text-white shadow-lg shadow-success/25">
                <x-ui.icon name="banknotes" class="h-7 w-7" />
            </span>
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-success">
                    <x-ui.icon name="sparkles" class="h-3 w-3" /> Setoran Tunggal
                </span>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">Setor Simpanan</h2>
                <p class="mt-1 max-w-md text-sm text-muted">Catat beberapa jenis simpanan sekaligus untuk satu anggota dalam satu proses.</p>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3 lg:items-start">
        {{-- KIRI: info setoran + jenis simpanan --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Card 1: Anggota & info setoran --}}
            <x-ui.card>
                <div class="flex items-center gap-2.5 border-b border-border pb-3">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-success/10 text-success">
                        <x-ui.icon name="user" class="h-4 w-4" />
                    </span>
                    <h3 class="text-sm font-semibold text-text">Anggota &amp; Info Setoran</h3>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        @include('livewire.savings.partials.member-picker')
                    </div>

                    {{-- Tanggal setor --}}
                    <div class="space-y-1">
                        <label for="deposit_date" class="block text-sm font-medium text-text">Tanggal Setor</label>
                        <input id="deposit_date" type="date" wire:model.live="deposit_date" max="{{ now()->toDateString() }}"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('deposit_date'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('deposit_date'),
                               ])>
                        @error('deposit_date')<p class="text-xs text-danger">{{ $message }}</p>
                        @else<p class="text-xs text-muted">Memunculkan Hari Raya sesuai rentang program aktif.</p>@enderror
                    </div>

                    {{-- Periode --}}
                    <div class="space-y-1">
                        <label for="period_month" class="block text-sm font-medium text-text">Periode (Bulan)</label>
                        <input id="period_month" type="month" wire:model.live="period_month"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('period_month'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('period_month'),
                               ])>
                        @error('period_month')<p class="text-xs text-danger">{{ $message }}</p>
                        @else<p class="text-xs text-muted">Satu jenis hanya boleh sekali per periode.</p>@enderror
                    </div>

                    {{-- Metode --}}
                    <div class="space-y-1">
                        <label for="deposit_method" class="block text-sm font-medium text-text">Metode Setor</label>
                        <select id="deposit_method" wire:model="deposit_method"
                                class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                            @foreach ($depositMethods as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('deposit_method')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>

                    {{-- Disetor oleh --}}
                    <div class="space-y-1">
                        <label for="deposited_by" class="block text-sm font-medium text-text">Disetor Oleh</label>
                        <select id="deposited_by" wire:model="deposited_by"
                                class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                            @foreach ($depositedByOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('deposited_by')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>

                    {{-- Referensi --}}
                    <div class="sm:col-span-2">
                        <x-ui.input label="No. Referensi (opsional)" name="reference_number" wire:model="reference_number"
                            placeholder="Nomor bukti / transfer" :error="$errors->first('reference_number')"
                            hint="Nomor bukti/transfer bila ada." />
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
            </x-ui.card>

            {{-- Card 2: Jenis simpanan --}}
            <x-ui.card>
                <div class="flex items-center justify-between gap-2 border-b border-border pb-3">
                    <div class="flex items-center gap-2.5">
                        <span class="grid h-7 w-7 place-items-center rounded-lg bg-success/10 text-success">
                            <x-ui.icon name="wallet-stack" class="h-4 w-4" />
                        </span>
                        <h3 class="text-sm font-semibold text-text">Jenis Simpanan</h3>
                    </div>
                    @unless (blank($member_id) || empty($lines))
                        <span class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2.5 py-0.5 text-xs font-semibold text-success">
                            <span x-text="count">0</span> dipilih
                        </span>
                    @endunless
                </div>

                @if (blank($member_id))
                    {{-- Empty: belum pilih anggota --}}
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="grid h-16 w-16 place-items-center rounded-2xl bg-linear-to-br from-success/15 to-primary/10 text-success">
                            <x-ui.icon name="user" class="h-8 w-8" />
                        </div>
                        <h4 class="mt-4 text-sm font-semibold text-text">Pilih anggota dulu</h4>
                        <p class="mt-1 max-w-xs text-xs text-muted">Jenis simpanan yang berlaku akan muncul otomatis setelah anggota dipilih.</p>
                    </div>
                @else
                    <p class="mt-4 text-xs text-muted">Ketuk kartu untuk memilih jenis yang disetor, lalu sesuaikan nominalnya.</p>

                    {{-- Catatan: jenis tersembunyi karena sudah disetor --}}
                    @if (! empty($hiddenTypes))
                        <div class="mt-3 flex items-start gap-2 rounded-xl bg-warning/5 px-3 py-2.5 text-xs text-warning ring-1 ring-inset ring-warning/15">
                            <x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>Disembunyikan karena sudah disetor periode ini: <span class="font-semibold">{{ implode(', ', $hiddenTypes) }}</span>.</span>
                        </div>
                    @endif

                    @if (empty($lines))
                        <div class="mt-4 rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted">
                            Tidak ada jenis simpanan yang bisa disetor untuk anggota &amp; periode ini.
                        </div>
                    @else
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach ($lines as $i => $line)
                                @php($type = $line['savings_type'])
                                @php($locked = $this->isLocked($type))
                                @php($t = $toneFor($this->typeColor($type)))
                                <label data-line data-label="{{ $line['type_label'] }}"
                                       @if($locked) data-amount-value="{{ (int) round((float) ($line['amount'] ?? 0)) }}" @endif
                                       wire:key="line-{{ $type }}"
                                       class="group relative flex cursor-pointer flex-col gap-3 rounded-2xl border border-border bg-surface p-4 ring-1 ring-transparent transition {{ $t['card'] }}">
                                    {{-- Seluruh kartu = toggle. Checkbox tersembunyi dikontrol via klik label. --}}
                                    <input type="checkbox" data-include wire:model="lines.{{ $i }}.include"
                                           class="sr-only" id="inc-{{ $i }}">

                                    <div class="flex items-center gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $t['ic'] }}">
                                            <x-ui.icon name="banknotes" class="h-5 w-5" />
                                        </span>
                                        <div class="min-w-0 flex-1 leading-tight">
                                            <p class="truncate text-sm font-semibold text-text">{{ $line['type_label'] }}</p>
                                            <p class="text-[11px] font-medium uppercase tracking-wide text-muted">
                                                {{ $locked ? 'Nominal terkunci' : 'Nominal bebas' }}
                                            </p>
                                        </div>
                                        {{-- Indikator centang (mengikuti state checkbox via group-has) --}}
                                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border-2 border-border text-white transition {{ $t['dot'] }}">
                                            <x-ui.icon name="check" class="h-3.5 w-3.5 scale-0 transition group-has-checked:scale-100" />
                                        </span>
                                    </div>

                                    {{-- Nominal --}}
                                    @if ($locked)
                                        <div class="flex items-center justify-between rounded-xl border border-border bg-bg/60 px-3 py-2.5">
                                            <span class="text-xs text-muted">Nominal</span>
                                            <span class="text-sm font-bold tabular-nums {{ $t['amt'] }}">
                                                Rp {{ number_format((float) ($line['amount'] ?? 0), 0, ',', '.') }}
                                            </span>
                                        </div>
                                    @else
                                        <div class="space-y-1"
                                             x-data="{
                                                raw: @entangle('lines.'.$i.'.amount'),
                                                display: '',
                                                fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                                                init() { this.display = this.fmt(this.raw); this.$watch('raw', (v) => { const f = this.fmt(v); if (f !== this.display) this.display = f; }); },
                                                onInput(e) { const d = e.target.value.replace(/\D/g, ''); this.raw = d === '' ? null : parseInt(d, 10); this.display = this.fmt(d); },
                                             }">
                                            <div @class([
                                                    'flex items-center rounded-xl border bg-surface transition focus-within:ring-2 focus-within:ring-primary',
                                                    'border-border' => ! $errors->has('lines.'.$i.'.amount'),
                                                    'border-danger focus-within:ring-danger' => $errors->has('lines.'.$i.'.amount'),
                                                 ])>
                                                <span class="pl-3 text-sm text-muted">Rp</span>
                                                <input type="text" inputmode="numeric" data-amount-input :value="display" @input="onInput($event)"
                                                       placeholder="0"
                                                       class="h-10 w-full rounded-xl bg-transparent px-2 text-sm font-medium tabular-nums text-text placeholder:text-muted focus-visible:outline-none">
                                            </div>
                                            @error('lines.'.$i.'.amount')
                                                <p class="text-xs text-danger">{{ $message }}</p>
                                            @else
                                                <p class="text-[11px] text-muted">{{ $this->typeHint($type) }}</p>
                                            @enderror
                                        </div>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @endif
                @endif
            </x-ui.card>
        </div>

        {{-- KANAN: ringkasan sticky --}}
        <div class="lg:sticky lg:top-24">
            <x-ui.card class="overflow-hidden p-0">
                {{-- Total --}}
                <div class="relative overflow-hidden bg-linear-to-br from-success to-primary px-5 py-6 text-white">
                    <div class="absolute -right-4 -top-6 h-24 w-24 rounded-full bg-white/10 blur-xl" aria-hidden="true"></div>
                    <p class="relative text-xs font-medium uppercase tracking-wide text-white/80">Total Setoran</p>
                    <p class="relative mt-1 text-3xl font-bold tabular-nums">
                        Rp <span x-text="rupiah(total)">0</span>
                    </p>
                    <p class="relative mt-1 text-xs text-white/80">
                        <span x-text="count">0</span> jenis simpanan dipilih
                    </p>
                </div>

                <div class="space-y-4 p-5">
                    {{-- Anggota terpilih --}}
                    @if (filled($member_id) && $selectedMemberLabel)
                        <div class="flex items-center gap-2.5 rounded-xl bg-bg/60 px-3 py-2.5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-success/10 text-success">
                                <x-ui.icon name="user" class="h-4 w-4" />
                            </span>
                            <span class="truncate text-sm font-medium text-text">{{ $selectedMemberLabel }}</span>
                        </div>
                    @endif

                    {{-- Rincian jenis dipilih (live) --}}
                    <div x-show="items.length" x-cloak class="space-y-1.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Rincian</p>
                        <template x-for="item in items" :key="item.label">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="flex items-center gap-1.5 truncate text-muted">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-success"></span>
                                    <span class="truncate" x-text="item.label"></span>
                                </span>
                                <span class="shrink-0 font-medium tabular-nums text-text">Rp <span x-text="rupiah(item.amount)"></span></span>
                            </div>
                        </template>
                    </div>

                    <div x-show="! items.length" class="rounded-xl border border-dashed border-border px-3 py-4 text-center text-xs text-muted">
                        Belum ada jenis simpanan dipilih.
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-success px-4 text-sm font-semibold text-white shadow-sm shadow-success/25 transition hover:bg-success/90 focus-visible:ring-2 focus-visible:ring-success focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60">
                        <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <x-ui.icon wire:loading.remove wire:target="save" name="check" class="h-4.5 w-4.5" />
                        Proses Setoran
                    </button>

                    <x-ui.button type="button" variant="ghost" :href="route('savings.deposits')" wire:navigate class="w-full">
                        Batal
                    </x-ui.button>

                    <p class="text-center text-[11px] leading-relaxed text-muted">
                        Tiap jenis dibuat sebagai transaksi terpisah. Koreksi hanya lewat <span class="font-medium text-text">reversal</span>.
                    </p>
                </div>
            </x-ui.card>
        </div>
    </form>

    <x-ui.toast-host />
</div>
