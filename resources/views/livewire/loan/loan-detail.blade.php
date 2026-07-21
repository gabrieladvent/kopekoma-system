@php
    $typeColor = $loan->loan_type === 'jangka_panjang' ? 'primary' : 'warning';
@endphp

<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('loans.index') }}" wire:navigate
        class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="receipt-percent" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $loan->loan_number }}</x-ui.badge>
                    <x-ui.badge :color="$typeColor">{{ $loanTypeLabel }}</x-ui.badge>
                    <x-ui.badge :color="$loan->status->color()">{{ $loan->status->label() }}</x-ui.badge>
                    @if ($progress['overdue'] > 0)
                        <x-ui.badge color="danger">{{ $progress['overdue'] }} tunggakan</x-ui.badge>
                    @endif
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $loan->member?->full_name ?? '—' }}</h2>
                <p class="font-mono text-sm text-muted">{{ $loan->member?->member_number }}</p>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($loan->status === \App\Enums\LoanStatus::Cair)
                @can('create_installment')
                    <x-ui.button :href="route('installments.create', ['loan' => $loan->id])" wire:navigate>
                        <x-ui.icon name="credit-card" class="h-4 w-4" /> Bayar Angsuran
                    </x-ui.button>
                @endcan
            @endif
            <x-ui.button variant="ghost" :href="route('loans.receipt', $loan)">
                <x-ui.icon name="printer" class="h-4 w-4" /> Tanda Terima
            </x-ui.button>
            @if ($this->canCorrect($loan))
                <x-ui.button variant="danger" wire:click="openCorrect">
                    <x-ui.icon name="arrow-uturn-left" class="h-4 w-4" /> Batalkan
                </x-ui.button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Info pinjaman --}}
            <x-ui.card>
                {{-- Dana diterima highlight --}}
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-primary/5 px-4 py-4 ring-1 ring-inset ring-primary/15 sm:col-span-1">
                        <p class="text-xs font-medium uppercase tracking-wide text-primary">Dana Diterima</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-primary">Rp
                            {{ number_format((float) $loan->disbursed_amount, 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl bg-bg/50 px-4 py-4 ring-1 ring-inset ring-border">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted">Jumlah Diajukan</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-text">Rp
                            {{ number_format((float) $loan->principal_amount, 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl bg-bg/50 px-4 py-4 ring-1 ring-inset ring-border">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted">Sisa Pokok</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-text">Rp
                            {{ number_format((float) $progress['remaining'], 0, ',', '.') }}</p>
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5">
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Tgl Pencairan
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $loan->disbursement_date?->translatedFormat('d M Y') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="clock" class="h-3.5 w-3.5" /> Jatuh Tempo Pertama
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ $loan->first_due_date?->translatedFormat('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="banknotes" class="h-3.5 w-3.5" /> Jenis Pencairan
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ \App\Filament\Resources\LoanResource::DISBURSEMENT_METHODS[$loan->disbursement_method] ?? '—' }}
                        </dd>
                    </div>
                    @if ($loan->disbursement_method === 'transfer')
                        <div>
                            <dt
                                class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="building-office" class="h-3.5 w-3.5" /> Bank Tujuan
                            </dt>
                            <dd class="mt-1 text-sm text-text">{{ $loan->disbursement_bank ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt
                                class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="hashtag" class="h-3.5 w-3.5" /> No. Rekening Tujuan
                            </dt>
                            <dd class="mt-1 text-sm text-text tabular-nums">
                                {{ $loan->disbursement_account_number ?? '—' }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Jangka Waktu
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $loan->term_months }} bulan</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="building-office" class="h-3.5 w-3.5" /> OPD / Instansi
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $loan->member?->agency?->agency_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="banknotes" class="h-3.5 w-3.5" /> Biaya Admin
                        </dt>
                        <dd class="mt-1 text-sm text-text">Rp
                            {{ number_format((float) $loan->admin_fee, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="wallet" class="h-3.5 w-3.5" /> SWP
                        </dt>
                        <dd class="mt-1 text-sm text-text">Rp
                            {{ number_format((float) $loan->swp_amount, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="user" class="h-3.5 w-3.5" /> Dicatat Oleh
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $loan->recordedBy?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Dicatat Pada
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $loan->created_at?->translatedFormat('d M Y · H.i') }}
                            WIB</dd>
                    </div>

                    {{-- Angsuran konstan / bulan --}}
                    <div class="col-span-2 rounded-xl border border-dashed border-border px-4 py-3">
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted">Tagihan Angsuran / Bulan</dt>
                        <dd class="mt-2 flex flex-wrap gap-x-6 gap-y-1.5 text-sm">
                            <span class="text-muted">Pokok <span class="font-medium tabular-nums text-text">Rp
                                    {{ number_format((float) $loan->monthly_principal, 0, ',', '.') }}</span></span>
                            <span class="text-muted">Jasa <span class="font-medium tabular-nums text-text">Rp
                                    {{ number_format((float) $loan->monthly_interest, 0, ',', '.') }}</span></span>
                            <span class="text-muted">Tab. Berjangka <span class="font-medium tabular-nums text-text">Rp
                                    {{ number_format((float) $loan->monthly_time_deposit, 0, ',', '.') }}</span></span>
                        </dd>
                    </div>

                    @if ($loan->notes)
                        <div class="col-span-2">
                            <dt
                                class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="document" class="h-3.5 w-3.5" /> Catatan
                            </dt>
                            <dd class="mt-1 text-sm text-text">{{ $loan->notes }}</dd>
                        </div>
                    @endif
                </dl>

                {{-- Dokumen --}}
                @if ($documents->isNotEmpty())
                    <div class="mt-5 border-t border-border pt-4">
                        <p
                            class="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="paper-clip" class="h-3.5 w-3.5" /> Dokumen
                        </p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($documents as $doc)
                                <a href="{{ route('media.show', $doc) }}" target="_blank"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-bg/40 px-3 py-2 text-sm text-text transition hover:border-primary/40 hover:text-primary">
                                    <x-ui.icon name="document" class="h-4 w-4 shrink-0" />
                                    <span class="max-w-[12rem] truncate">{{ $doc->name }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Progres angsuran --}}
            <x-ui.card class="p-0">
                <div class="border-b border-border p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <span class="grid h-7 w-7 place-items-center rounded-lg bg-primary/10 text-primary">
                                <x-ui.icon name="list-bullet" class="h-4 w-4" />
                            </span>
                            <h3 class="text-sm font-semibold text-text">Progres Angsuran</h3>
                        </div>
                        <button type="button" wire:click="$toggle('showAllSchedules')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-muted transition hover:border-primary/40 hover:text-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                            <x-ui.icon :name="$showAllSchedules ? 'eye' : 'list-bullet'" class="h-3.5 w-3.5" />
                            {{ $showAllSchedules ? 'Sembunyikan rancangan' : 'Tampilkan rancangan angsuran' }}
                        </button>
                    </div>

                    {{-- Progress bar --}}
                    <div class="mt-4 space-y-1.5">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-text">{{ $progress['percent'] }}% terbayar</span>
                            <span class="text-muted">
                                {{ $progress['paid'] }}/{{ $progress['total'] }} lunas
                                @if ($progress['overdue'] > 0)
                                    · <span class="font-medium text-danger">{{ $progress['overdue'] }} nunggak</span>
                                @endif
                            </span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-border/60">
                            <div class="h-2.5 rounded-full bg-linear-to-r from-primary to-secondary transition-all"
                                style="width: {{ $progress['percent'] }}%"></div>
                        </div>
                        <p class="text-xs text-muted">Sisa pokok Rp
                            {{ number_format((float) $progress['remaining'], 0, ',', '.') }}</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                            <tr>
                                <th class="px-5 py-3 text-left">#</th>
                                <th class="px-5 py-3 text-left">Jatuh Tempo</th>
                                <th class="px-5 py-3 text-right">Tagihan</th>
                                <th class="px-5 py-3 text-right">Dibayar</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th class="px-5 py-3 text-left">Tgl Bayar</th>
                                <th class="w-10 px-5 py-3"></th>
                            </tr>
                        </thead>
                        @php($canViewInstallment = auth()->user()?->can('view_installment') ?? false)
                        <tbody class="divide-y divide-border">
                            @forelse ($schedules as $schedule)
                                @php($label = $this->scheduleStatusLabel($schedule))
                                @php($payment = $schedule->status === \App\Enums\InstallmentScheduleStatus::Terbayar ? $this->actualPayment($schedule) : null)
                                @php($clickable = $payment && $canViewInstallment)
                                <tr wire:key="sched-{{ $schedule->id }}" @class(['transition hover:bg-bg/50', 'cursor-pointer' => $clickable])
                                    @if ($clickable) @click="window.Livewire.navigate('{{ route('installments.show', $payment) }}')"
                                        title="Lihat detail pembayaran {{ $payment->installment_number }}" @endif>
                                    <td class="px-5 py-3 font-medium text-text">{{ $schedule->installment_seq }}</td>
                                    <td class="px-5 py-3 text-text">
                                        {{ $schedule->due_date?->translatedFormat('d M Y') }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-text">Rp
                                        {{ number_format((float) $schedule->total_due, 0, ',', '.') }}</td>
                                    <td
                                        class="px-5 py-3 text-right tabular-nums {{ $payment ? 'font-medium text-success' : 'text-muted' }}">
                                        {{ $payment ? 'Rp ' . number_format((float) $payment->amount_paid, 0, ',', '.') : '—' }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <x-ui.badge :color="$this->scheduleStatusColor($label) === 'gray' ? 'neutral' : $this->scheduleStatusColor($label)">{{ $label }}</x-ui.badge>
                                    </td>
                                    <td class="px-5 py-3 text-muted">
                                        {{ $payment?->payment_date?->translatedFormat('d M Y') ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right">
                                        @if ($clickable)
                                            <x-ui.icon name="chevron-right" class="ml-auto h-4 w-4 text-muted" />
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-10">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <div
                                                class="grid h-12 w-12 place-items-center rounded-2xl bg-border/60 text-muted">
                                                <x-ui.icon name="clock" class="h-6 w-6" />
                                            </div>
                                            <p class="mt-3 text-sm text-text">Belum ada angsuran terbayar</p>
                                            <p class="mt-1 text-xs text-muted">Klik <span
                                                    class="font-medium text-text">Tampilkan rancangan angsuran</span>
                                                untuk melihat jadwal lengkap.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($schedules->hasPages())
                    <div class="border-t border-border px-5 py-3">
                        {{ $schedules->links() }}
                    </div>
                @endif

                @if (!$showAllSchedules && $progress['total'] > $progress['paid'])
                    <button type="button" wire:click="$toggle('showAllSchedules')"
                        class="flex w-full items-center justify-center gap-1.5 border-t border-border px-5 py-3 text-xs font-medium text-muted transition hover:bg-bg/50 hover:text-primary">
                        <x-ui.icon name="list-bullet" class="h-3.5 w-3.5" />
                        Tampilkan rancangan angsuran ({{ $progress['total'] - $progress['paid'] }} belum terbayar)
                    </button>
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

    {{-- Modal: Koreksi --}}
    <div x-data="{ show: @entangle('showCorrect').live }" x-show="show" x-cloak
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
                    <h3 class="text-base font-semibold tracking-tight text-text">Batalkan Pinjaman Salah-Input</h3>
                    <p class="mt-1 text-xs text-muted">Pinjaman ditandai <span
                            class="font-medium text-text">Dibatalkan</span> dan tetap tersimpan sebagai histori; jadwal
                        proyeksinya dibersihkan, dicatat di audit. Hanya untuk pinjaman yang belum punya angsuran.</p>
                </div>
            </div>

            <form wire:submit="performCorrect" class="mt-5 space-y-4">
                <div class="space-y-1">
                    <label for="correctReason" class="block text-sm font-medium text-text">Alasan Pembatalan</label>
                    <textarea id="correctReason" wire:model="correctReason" rows="3"
                        placeholder="Wajib, minimal 5 karakter. Akan tercatat di log audit." @class([
                            'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                            'border-border' => !$errors->has('correctReason'),
                            'border-danger focus-visible:ring-danger' => $errors->has('correctReason'),
                        ])></textarea>
                    @error('correctReason')
                        <p class="text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled"
                        wire:target="performCorrect">
                        <svg wire:loading wire:target="performCorrect" class="h-4 w-4 animate-spin" fill="none"
                            viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z">
                            </path>
                        </svg>
                        Batalkan Pinjaman
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>

    <x-ui.toast-host />
</div>
