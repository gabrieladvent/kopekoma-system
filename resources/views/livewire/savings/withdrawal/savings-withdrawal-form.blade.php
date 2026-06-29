@php
    // Nada warna per jenis — ikut warna brand (Settings): sukarela=primary, hari_raya=secondary.
    $tones = [
        'primary'   => ['ic' => 'bg-primary/10 text-primary',     'amt' => 'text-primary',   'dot' => 'group-has-checked:border-primary group-has-checked:bg-primary',     'card' => 'has-checked:border-primary/50 has-checked:bg-primary/5 has-checked:ring-primary/10 hover:border-primary/40'],
        'secondary' => ['ic' => 'bg-secondary/10 text-secondary', 'amt' => 'text-secondary', 'dot' => 'group-has-checked:border-secondary group-has-checked:bg-secondary', 'card' => 'has-checked:border-secondary/50 has-checked:bg-secondary/5 has-checked:ring-secondary/10 hover:border-secondary/40'],
    ];
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
                const amt = parseInt(String(inp ? inp.value : '0').replace(/\D/g, ''), 10) || 0;
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
    <a href="{{ route('savings.withdrawals') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Hero header --}}
    <div class="relative overflow-hidden rounded-3xl border border-border bg-linear-to-br from-primary/12 via-surface to-secondary/8 px-6 py-7 sm:px-8">
        <div class="bg-grid pointer-events-none absolute inset-0 opacity-60" aria-hidden="true"></div>
        <div class="absolute -right-6 -top-8 h-32 w-32 rounded-full bg-primary/10 blur-2xl" aria-hidden="true"></div>
        <div class="relative flex items-start gap-4">
            <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/25">
                <x-ui.icon name="arrow-up-tray" class="h-7 w-7" />
            </span>
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-primary">
                    <x-ui.icon name="sparkles" class="h-3 w-3" /> Pengajuan Pencairan
                </span>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">Pencairan Simpanan</h2>
                <p class="mt-1 max-w-md text-sm text-muted">Cairkan beberapa jenis simpanan sekaligus untuk satu anggota. Tiap jenis dibuat sebagai pengajuan <span class="font-medium text-text">draft</span> terpisah — dana keluar setelah ACC &amp; pencairan pengurus.</p>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3 lg:items-start">
        {{-- KIRI --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Card 1: Anggota & info --}}
            <x-ui.card>
                <div class="flex items-center gap-2.5 border-b border-border pb-3">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-primary/10 text-primary">
                        <x-ui.icon name="user" class="h-4 w-4" />
                    </span>
                    <h3 class="text-sm font-semibold text-text">Anggota &amp; Info Pengajuan</h3>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        @include('livewire.savings.partials.member-picker')
                    </div>

                    <div class="space-y-1">
                        <label for="withdrawal_date" class="block text-sm font-medium text-text">Tanggal Pengajuan</label>
                        <input id="withdrawal_date" type="date" wire:model="withdrawal_date" max="{{ now()->toDateString() }}"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('withdrawal_date'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('withdrawal_date'),
                               ])>
                        @error('withdrawal_date')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>

                    <div class="space-y-1">
                        <label for="disbursement_method" class="block text-sm font-medium text-text">Jenis Pencairan <span class="text-muted">(opsional)</span></label>
                        <select id="disbursement_method" wire:model="disbursement_method"
                                @class([
                                    'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                    'border-border' => ! $errors->has('disbursement_method'),
                                    'border-danger focus-visible:ring-danger' => $errors->has('disbursement_method'),
                                ])>
                            <option value="">Pilih jenis pencairan…</option>
                            @foreach ($disbursementMethods as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('disbursement_method')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>

                    <div class="space-y-1 sm:col-span-2">
                        <label for="notes" class="block text-sm font-medium text-text">Catatan <span class="text-muted">(opsional)</span></label>
                        <textarea id="notes" wire:model="notes" rows="2" placeholder="Alasan / keterangan pengajuan…"
                                  @class([
                                      'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                      'border-border' => ! $errors->has('notes'),
                                      'border-danger focus-visible:ring-danger' => $errors->has('notes'),
                                  ])></textarea>
                        @error('notes')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        <p class="text-[11px] text-muted">Catatan ini disalin ke semua pengajuan yang dibuat.</p>
                    </div>
                </div>
            </x-ui.card>

            {{-- Card 2: Sumber pencairan --}}
            <x-ui.card>
                <div class="flex items-center justify-between gap-2 border-b border-border pb-3">
                    <div class="flex items-center gap-2.5">
                        <span class="grid h-7 w-7 place-items-center rounded-lg bg-primary/10 text-primary">
                            <x-ui.icon name="wallet-stack" class="h-4 w-4" />
                        </span>
                        <h3 class="text-sm font-semibold text-text">Sumber Pencairan</h3>
                    </div>
                    @unless (blank($member_id) || empty($lines))
                        <span class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                            <span x-text="count">0</span> dipilih
                        </span>
                    @endunless
                </div>

                @if (blank($member_id))
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="grid h-16 w-16 place-items-center rounded-2xl bg-linear-to-br from-primary/15 to-secondary/10 text-primary">
                            <x-ui.icon name="user" class="h-8 w-8" />
                        </div>
                        <h4 class="mt-4 text-sm font-semibold text-text">Pilih anggota dulu</h4>
                        <p class="mt-1 max-w-xs text-xs text-muted">Jenis simpanan yang bersaldo &amp; bisa dicairkan akan muncul otomatis.</p>
                    </div>
                @elseif (empty($lines))
                    <div class="mt-4 rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-muted">
                        Tidak ada saldo simpanan yang bisa dicairkan untuk anggota ini.
                        <span class="mt-1 block text-xs">Hanya <span class="font-medium text-text">Sukarela</span> &amp; <span class="font-medium text-text">Hari Raya</span> yang dapat dicairkan saat ini.</span>
                    </div>
                @else
                    <p class="mt-4 text-xs text-muted">Ketuk kartu untuk memilih sumber yang dicairkan, lalu isi nominal (tak boleh melebihi saldo).</p>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        @foreach ($lines as $i => $line)
                            @php($type = $line['savings_type'])
                            @php($t = $tones[$this->typeColor($type)])
                            <label data-line data-label="{{ $line['type_label'] }}"
                                   wire:key="wd-line-{{ $type }}-{{ $line['period_year'] ?? 'x' }}"
                                   class="group relative flex cursor-pointer flex-col gap-3 rounded-2xl border border-border bg-surface p-4 ring-1 ring-transparent transition {{ $t['card'] }}">
                                <input type="checkbox" data-include wire:model="lines.{{ $i }}.include" class="sr-only" id="wd-inc-{{ $i }}">

                                <div class="flex items-center gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $t['ic'] }}">
                                        <x-ui.icon name="{{ $type === 'hari_raya' ? 'gift' : 'wallet-stack' }}" class="h-5 w-5" />
                                    </span>
                                    <div class="min-w-0 flex-1 leading-tight">
                                        <p class="truncate text-sm font-semibold text-text">{{ $line['type_label'] }}</p>
                                        <p class="text-[11px] font-medium uppercase tracking-wide text-muted">
                                            Saldo Rp {{ number_format((float) $line['balance'], 0, ',', '.') }}
                                        </p>
                                    </div>
                                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border-2 border-border text-white transition {{ $t['dot'] }}">
                                        <x-ui.icon name="check" class="h-3.5 w-3.5 scale-0 transition group-has-checked:scale-100" />
                                    </span>
                                </div>

                                {{-- Nominal --}}
                                <div class="space-y-1"
                                     x-data="{
                                        raw: @entangle('lines.'.$i.'.amount'),
                                        max: @js((int) round((float) $line['balance'])),
                                        display: '',
                                        fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                                        init() { this.display = this.fmt(this.raw); this.$watch('raw', (v) => { const f = this.fmt(v); if (f !== this.display) this.display = f; }); },
                                        onInput(e) {
                                            let d = parseInt(e.target.value.replace(/\D/g, ''), 10) || 0;
                                            if (d > this.max) d = this.max;
                                            this.raw = d === 0 ? null : d;
                                            this.display = this.fmt(d);
                                        },
                                        fill() { this.raw = this.max; this.display = this.fmt(this.max); },
                                     }">
                                    <div @class([
                                            'flex items-center rounded-xl border bg-surface transition focus-within:ring-2 focus-within:ring-primary',
                                            'border-border' => ! $errors->has('lines.'.$i.'.amount'),
                                            'border-danger focus-within:ring-danger' => $errors->has('lines.'.$i.'.amount'),
                                         ])>
                                        <span class="pl-3 text-sm text-muted">Rp</span>
                                        <input type="text" inputmode="numeric" data-amount-input :value="display" @input="onInput($event)"
                                               placeholder="0"
                                               class="h-10 w-full bg-transparent px-2 text-sm font-medium tabular-nums text-text placeholder:text-muted focus-visible:outline-none">
                                        <button type="button" @click="fill()"
                                                class="mr-2 shrink-0 rounded-md px-2 py-1 text-[11px] font-semibold {{ $t['amt'] }} transition hover:bg-bg/60">
                                            Semua
                                        </button>
                                    </div>
                                    @error('lines.'.$i.'.amount')
                                        <p class="text-xs text-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- KANAN: ringkasan sticky --}}
        <div class="lg:sticky lg:top-24">
            <x-ui.card class="overflow-hidden p-0">
                <div class="relative overflow-hidden bg-linear-to-br from-primary to-secondary px-5 py-6 text-white">
                    <div class="absolute -right-4 -top-6 h-24 w-24 rounded-full bg-white/10 blur-xl" aria-hidden="true"></div>
                    <p class="relative text-xs font-medium uppercase tracking-wide text-white/80">Total Pencairan</p>
                    <p class="relative mt-1 text-3xl font-bold tabular-nums">Rp <span x-text="rupiah(total)">0</span></p>
                    <p class="relative mt-1 text-xs text-white/80"><span x-text="count">0</span> sumber dipilih</p>
                </div>

                <div class="space-y-4 p-5">
                    @if (filled($member_id) && $selectedMemberLabel)
                        <div class="flex items-center gap-2.5 rounded-xl bg-bg/60 px-3 py-2.5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                                <x-ui.icon name="user" class="h-4 w-4" />
                            </span>
                            <span class="truncate text-sm font-medium text-text">{{ $selectedMemberLabel }}</span>
                        </div>
                    @endif

                    <div x-show="items.length" x-cloak class="space-y-1.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Rincian</p>
                        <template x-for="item in items" :key="item.label">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="flex items-center gap-1.5 truncate text-muted">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                                    <span class="truncate" x-text="item.label"></span>
                                </span>
                                <span class="shrink-0 font-medium tabular-nums text-text">Rp <span x-text="rupiah(item.amount)"></span></span>
                            </div>
                        </template>
                    </div>

                    <div x-show="! items.length" class="rounded-xl border border-dashed border-border px-3 py-4 text-center text-xs text-muted">
                        Belum ada sumber pencairan dipilih.
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="save" :disabled="count < 1"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-semibold text-white shadow-sm shadow-primary/25 transition hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60">
                        <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <x-ui.icon wire:loading.remove wire:target="save" name="arrow-up-tray" class="h-4.5 w-4.5" />
                        Ajukan Pencairan
                    </button>

                    <x-ui.button type="button" variant="ghost" :href="route('savings.withdrawals')" wire:navigate class="w-full">
                        Batal
                    </x-ui.button>

                    <p class="text-center text-[11px] leading-relaxed text-muted">
                        Tiap sumber dibuat sebagai pengajuan <span class="font-medium text-text">draft</span> terpisah. ACC &amp; pencairan dana oleh pengurus. Koreksi hanya lewat <span class="font-medium text-text">reversal</span>.
                    </p>
                </div>
            </x-ui.card>
        </div>
    </form>

    <x-ui.toast-host />
</div>
