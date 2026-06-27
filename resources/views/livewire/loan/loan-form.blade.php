<div class="mx-auto max-w-full space-y-6">
    {{-- Back --}}
    <a href="{{ route('loans.index') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Hero header --}}
    <div class="relative overflow-hidden rounded-3xl border border-border bg-linear-to-br from-primary/12 via-surface to-secondary/10 px-6 py-7 sm:px-8">
        <div class="bg-grid pointer-events-none absolute inset-0 opacity-60" aria-hidden="true"></div>
        <div class="absolute -right-6 -top-8 h-32 w-32 rounded-full bg-primary/10 blur-2xl" aria-hidden="true"></div>
        <div class="relative flex items-start gap-4">
            <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/25">
                <x-ui.icon name="receipt-percent" class="h-7 w-7" />
            </span>
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-primary">
                    <x-ui.icon name="sparkles" class="h-3 w-3" /> Pencatatan Akad
                </span>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">Pinjaman Baru</h2>
                <p class="mt-1 max-w-md text-sm text-muted">Catat pinjaman yang sudah disetujui (ACC). Potongan &amp; angsuran dihitung otomatis dari ketentuan koperasi.</p>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3 lg:items-start">
        {{-- KIRI: form --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Card 1: Anggota & detail --}}
            <x-ui.card>
                <div class="flex items-center gap-2.5 border-b border-border pb-3">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-primary/10 text-primary">
                        <x-ui.icon name="user" class="h-4 w-4" />
                    </span>
                    <h3 class="text-sm font-semibold text-text">Anggota &amp; Detail Pinjaman</h3>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        @include('livewire.savings.partials.member-picker', ['label' => 'Anggota Peminjam'])
                    </div>

                    {{-- Jenis pinjaman: kartu pilihan --}}
                    <div class="space-y-1.5 sm:col-span-2">
                        <label class="block text-sm font-medium text-text">Jenis Pinjaman</label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @php($typeMeta = [
                                'jangka_panjang' => ['icon' => 'calendar', 'tone' => 'primary', 'desc' => 'Di atas Rp '.number_format($this->shortTermMax(), 0, ',', '.').', diangsur per bulan.'],
                                'jangka_pendek' => ['icon' => 'bolt', 'tone' => 'warning', 'desc' => 'Sebrakan ≤ Rp '.number_format($this->shortTermMax(), 0, ',', '.').', 1× lunas tanpa jasa.'],
                            ])
                            @foreach ($loanTypes as $value => $label)
                                @php($meta = $typeMeta[$value])
                                @php($active = $loan_type === $value)
                                <label wire:key="type-{{ $value }}"
                                       @class([
                                           'group relative flex cursor-pointer gap-3 rounded-2xl border bg-surface p-4 transition',
                                           'border-primary/50 bg-primary/5 ring-1 ring-primary/10' => $active && $meta['tone'] === 'primary',
                                           'border-warning/50 bg-warning/5 ring-1 ring-warning/10' => $active && $meta['tone'] === 'warning',
                                           'border-border hover:border-primary/40' => ! $active,
                                       ])>
                                    <input type="radio" wire:model.live="loan_type" value="{{ $value }}" class="sr-only">
                                    <span @class([
                                        'grid h-9 w-9 shrink-0 place-items-center rounded-xl',
                                        'bg-primary/10 text-primary' => $meta['tone'] === 'primary',
                                        'bg-warning/10 text-warning' => $meta['tone'] === 'warning',
                                    ])>
                                        <x-ui.icon :name="$meta['icon']" class="h-5 w-5" />
                                    </span>
                                    <span class="min-w-0 flex-1 leading-tight">
                                        <span class="block text-sm font-semibold text-text">{{ $label }}</span>
                                        <span class="mt-0.5 block text-[11px] text-muted">{{ $meta['desc'] }}</span>
                                    </span>
                                    <span @class([
                                        'mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border-2 transition',
                                        'border-primary bg-primary text-white' => $active && $meta['tone'] === 'primary',
                                        'border-warning bg-warning text-white' => $active && $meta['tone'] === 'warning',
                                        'border-border text-transparent' => ! $active,
                                    ])>
                                        <x-ui.icon name="check" class="h-3 w-3" />
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('loan_type')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>

                    {{-- Jumlah pinjaman (masked rupiah, live ke simulator) --}}
                    <div class="space-y-1 sm:col-span-2"
                         x-data="{
                            display: @js($principal_amount ? number_format($principal_amount, 0, ',', '.') : ''),
                            t: null,
                            fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                            onInput(e) {
                                const d = e.target.value.replace(/\D/g, '');
                                this.display = this.fmt(d);
                                clearTimeout(this.t);
                                this.t = setTimeout(() => $wire.set('principal_amount', d === '' ? null : parseInt(d, 10)), 450);
                            },
                         }">
                        <label for="principal_amount" class="block text-sm font-medium text-text">Jumlah Pinjaman Diajukan</label>
                        <div @class([
                                'flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary',
                                'border-border' => ! $errors->has('principal_amount'),
                                'border-danger focus-within:ring-danger' => $errors->has('principal_amount'),
                             ])>
                            <span class="pl-3 text-sm font-medium text-muted">Rp</span>
                            <input id="principal_amount" type="text" inputmode="numeric" :value="display" @input="onInput($event)"
                                   placeholder="0"
                                   class="h-11 w-full rounded-lg bg-transparent px-2 text-base font-semibold tabular-nums text-text placeholder:text-muted focus-visible:outline-none">
                        </div>
                        @error('principal_amount')<p class="text-xs text-danger">{{ $message }}</p>
                        @else<p class="text-xs text-muted">{{ $this->isShortTerm() ? 'Maksimal Rp '.number_format($this->shortTermMax(), 0, ',', '.').' untuk Sebrakan.' : 'Di atas Rp '.number_format($this->shortTermMax(), 0, ',', '.').' untuk jangka panjang.' }}</p>@enderror
                    </div>

                    {{-- Jangka waktu --}}
                    <div class="space-y-1">
                        <label for="term_months" class="block text-sm font-medium text-text">Jangka Waktu (bulan)</label>
                        <input id="term_months" type="number" min="1" max="120" wire:model.live.debounce.400ms="term_months"
                               @disabled($this->isShortTerm())
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-bg/60 disabled:text-muted',
                                   'border-border' => ! $errors->has('term_months'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('term_months'),
                               ])>
                        @error('term_months')<p class="text-xs text-danger">{{ $message }}</p>
                        @else<p class="text-xs text-muted">{{ $this->isShortTerm() ? 'Sebrakan otomatis 1 bulan.' : 'Jumlah angsuran bulanan.' }}</p>@enderror
                    </div>

                    {{-- Tanggal pencairan --}}
                    <div class="space-y-1">
                        <label for="disbursement_date" class="block text-sm font-medium text-text">Tanggal Pencairan</label>
                        <input id="disbursement_date" type="date" wire:model.live="disbursement_date"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('disbursement_date'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('disbursement_date'),
                               ])>
                        @error('disbursement_date')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>

                    {{-- Jatuh tempo pertama --}}
                    <div class="space-y-1">
                        <label for="first_due_date" class="block text-sm font-medium text-text">Jatuh Tempo Pertama</label>
                        <input id="first_due_date" type="date" wire:model="first_due_date"
                               @class([
                                   'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                   'border-border' => ! $errors->has('first_due_date'),
                                   'border-danger focus-visible:ring-danger' => $errors->has('first_due_date'),
                               ])>
                        @error('first_due_date')<p class="text-xs text-danger">{{ $message }}</p>
                        @else<p class="text-xs text-muted">Default satu bulan setelah pencairan.</p>@enderror
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

            {{-- Peringatan riwayat & kapasitas --}}
            @if (filled($member_id) && ($arrears['warning'] || $arrears['load']))
                <div class="space-y-3">
                    @if ($arrears['warning'])
                        <div class="flex items-start gap-3 rounded-2xl border border-warning/20 bg-warning/5 px-4 py-3.5">
                            <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-warning/10 text-warning">
                                <x-ui.icon name="exclamation-triangle" class="h-4.5 w-4.5" />
                            </span>
                            <div class="text-sm">
                                <p class="font-semibold text-warning">Perhatikan riwayat angsuran</p>
                                <p class="mt-0.5 text-muted">{{ $arrears['warning'] }}</p>
                            </div>
                        </div>
                    @endif
                    @if ($arrears['load'])
                        <div class="flex items-start gap-3 rounded-2xl border border-border bg-bg/50 px-4 py-3.5">
                            <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary/10 text-secondary">
                                <x-ui.icon name="scale" class="h-4.5 w-4.5" />
                            </span>
                            <div class="text-sm">
                                <p class="font-semibold text-text">Potongan gaji berjalan</p>
                                <p class="mt-0.5 text-muted">Rp {{ number_format((float) $arrears['load'], 0, ',', '.') }} / bulan (pinjaman aktif + simpanan wajib). Verifikasi kemampuan potong gaji tetap manual.</p>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Card 2: Dokumen --}}
            <x-ui.card>
                <div class="flex items-center gap-2.5 border-b border-border pb-3">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-primary/10 text-primary">
                        <x-ui.icon name="paper-clip" class="h-4 w-4" />
                    </span>
                    <h3 class="text-sm font-semibold text-text">Dokumen Pinjaman <span class="font-normal text-muted">(opsional)</span></h3>
                </div>

                <div class="mt-4 space-y-3">
                    <label for="uploads"
                           class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-border px-4 py-8 text-center transition hover:border-primary/40 hover:bg-primary/5">
                        <span class="grid h-10 w-10 place-items-center rounded-full bg-primary/10 text-primary">
                            <x-ui.icon name="arrow-up-tray" class="h-5 w-5" />
                        </span>
                        <span class="text-sm font-medium text-text">Unggah formulir / tanda terima</span>
                        <span class="text-xs text-muted">PDF, JPG, atau PNG · maks. 5 MB / berkas</span>
                        <input id="uploads" type="file" wire:model="uploads" multiple accept=".pdf,.jpg,.jpeg,.png" class="hidden">
                    </label>

                    <div wire:loading wire:target="uploads" class="flex items-center gap-2 text-xs text-muted">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Mengunggah…
                    </div>

                    @error('uploads.*')<p class="text-xs text-danger">{{ $message }}</p>@enderror

                    @if (! empty($uploads))
                        <ul class="space-y-2">
                            @foreach ($uploads as $i => $file)
                                <li wire:key="up-{{ $i }}" class="flex items-center gap-3 rounded-lg border border-border bg-bg/40 px-3 py-2">
                                    <x-ui.icon name="document" class="h-4 w-4 shrink-0 text-muted" />
                                    <span class="min-w-0 flex-1 truncate text-sm text-text">{{ $file->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="removeUpload({{ $i }})"
                                            class="grid h-7 w-7 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-danger/10 hover:text-danger">
                                        <x-ui.icon name="x" class="h-4 w-4" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </x-ui.card>
        </div>

        {{-- KANAN: simulasi sticky --}}
        <div class="lg:sticky lg:top-24">
            <x-ui.card class="overflow-hidden p-0">
                {{-- Dana diterima --}}
                <div class="relative overflow-hidden bg-linear-to-br from-primary to-secondary px-5 py-6 text-white">
                    <div class="absolute -right-4 -top-6 h-24 w-24 rounded-full bg-white/10 blur-xl" aria-hidden="true"></div>
                    <div class="relative flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-white/80">
                        <x-ui.icon name="calculator" class="h-3.5 w-3.5" /> Simulasi · Dana Diterima
                    </div>
                    <p class="relative mt-1 text-3xl font-bold tabular-nums">
                        Rp {{ number_format((float) $preview['disbursed_amount'], 0, ',', '.') }}
                    </p>
                    <p class="relative mt-1 text-xs text-white/80">
                        @if ($preview['has'])
                            dari pinjaman Rp {{ number_format((float) $principal_amount, 0, ',', '.') }}
                        @else
                            Isi jumlah pinjaman untuk melihat rincian
                        @endif
                    </p>
                </div>

                <div class="space-y-5 p-5">
                    {{-- Potongan pencairan --}}
                    <div class="space-y-2.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Potongan Saat Pencairan</p>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-muted">Biaya Admin <span class="text-xs text-muted/70">({{ $preview['admin_rate'] }})</span></span>
                            <span class="font-medium tabular-nums text-text">Rp {{ number_format((float) $preview['admin_fee'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-muted">SWP <span class="text-xs text-muted/70">({{ $preview['swp_rate'] }})</span></span>
                            <span class="font-medium tabular-nums text-text">Rp {{ number_format((float) $preview['swp_amount'], 0, ',', '.') }}</span>
                        </div>
                    </div>

                    <div class="border-t border-dashed border-border"></div>

                    {{-- Angsuran per bulan --}}
                    <div class="space-y-2.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Angsuran / Bulan</p>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-muted">Pokok</span>
                            <span class="font-medium tabular-nums text-text">Rp {{ number_format((float) $preview['monthly_principal'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-muted">Jasa <span class="text-xs text-muted/70">({{ $preview['interest_rate'] }})</span></span>
                            <span class="font-medium tabular-nums text-text">Rp {{ number_format((float) $preview['monthly_interest'], 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-muted">Tab. Berjangka <span class="text-xs text-muted/70">({{ $preview['time_deposit_rate'] }})</span></span>
                            <span class="font-medium tabular-nums text-text">Rp {{ number_format((float) $preview['monthly_time_deposit'], 0, ',', '.') }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between rounded-xl bg-primary/5 px-3 py-2.5">
                            <span class="text-sm font-semibold text-primary">Total / bulan</span>
                            <span class="text-base font-bold tabular-nums text-primary">Rp {{ number_format((float) $preview['monthly_total'], 0, ',', '.') }}</span>
                        </div>
                        <p class="text-center text-[11px] text-muted">
                            {{ $this->isShortTerm() ? '1× pelunasan (Sebrakan)' : ($term_months ?: 0).'× angsuran · total Rp '.number_format((float) $preview['total_repayment'], 0, ',', '.') }}
                        </p>
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-semibold text-white shadow-sm shadow-primary/25 transition hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60">
                        <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <x-ui.icon wire:loading.remove wire:target="save" name="check" class="h-4.5 w-4.5" />
                        Catat Pinjaman
                    </button>

                    <x-ui.button type="button" variant="ghost" :href="route('loans.index')" wire:navigate class="w-full">
                        Batal
                    </x-ui.button>

                    <p class="text-center text-[11px] leading-relaxed text-muted">
                        Rincian dihitung server dari ketentuan koperasi. Koreksi hanya lewat <span class="font-medium text-text">reversal</span>.
                    </p>
                </div>
            </x-ui.card>
        </div>
    </form>

    <x-ui.toast-host />
</div>
