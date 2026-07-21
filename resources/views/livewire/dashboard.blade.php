@php
    $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
@endphp

<div class="space-y-8">
    {{-- Sapaan --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success/70"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-success"></span>
                </span>
                {{ \Illuminate\Support\Carbon::now()->translatedFormat('l, d F Y') }}
            </p>
            <h2 class="mt-2 text-3xl font-bold tracking-tight">{{ $greeting }}, {{ $firstName }} 👋</h2>
            <p class="mt-1 text-sm text-muted">Ringkasan koperasi hari ini.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('create_savings::deposit')
                <x-ui.button :href="route('savings.deposits.create')" wire:navigate class="h-9 px-3.5">
                    <x-ui.icon name="plus" class="h-4 w-4" /> Setor Simpanan
                </x-ui.button>
            @endcan
            @can('create_installment')
                <x-ui.button variant="ghost" :href="route('installments.create')" wire:navigate class="h-9 px-3.5">
                    <x-ui.icon name="credit-card" class="h-4 w-4" /> Bayar Angsuran
                </x-ui.button>
            @endcan
        </div>
    </div>

    @if (! $canFinance && ! $canMembers && ! $canLoan)
        <x-ui.card>
            <div class="flex flex-col items-center justify-center py-14 text-center">
                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-primary/10 text-primary">
                    <x-ui.icon name="home" class="h-6 w-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold">Selamat datang di KOPEKOMA</h3>
                <p class="mt-1 max-w-xs text-xs text-muted">Gunakan menu di samping untuk mulai bekerja. Ringkasan akan tampil sesuai izin akun Anda.</p>
            </div>
        </x-ui.card>
    @endif

    {{-- Hero keuangan: Simpanan (gradient) + Pinjaman (kartu bersih) --}}
    @if ($canFinance || $canLoan)
        <section @class(['grid gap-5', 'lg:grid-cols-2' => $canFinance && $canLoan])>
            {{-- Simpanan --}}
            @if ($canFinance)
                <div class="bg-brand-gradient relative flex flex-col overflow-hidden rounded-3xl p-6 text-white shadow-sm sm:p-7">
                    <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-3xl"></div>
                    <div class="relative flex items-start justify-between">
                        <div>
                            <p class="inline-flex items-center gap-1.5 text-sm font-medium text-white/80">
                                Total Simpanan
                                @if ($finance['this_month_delta'] !== null)
                                    <span class="rounded-full bg-white/15 px-2 py-0.5 text-xs font-semibold backdrop-blur">
                                        {{ $finance['this_month_delta'] >= 0 ? '↑' : '↓' }} {{ abs($finance['this_month_delta']) }}% MoM
                                    </span>
                                @endif
                            </p>
                            <p class="mt-2 text-4xl font-bold tracking-tight tabular-nums">{{ $rupiah($finance['total_balance']) }}</p>
                        </div>
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white/15 backdrop-blur">
                            <x-ui.icon name="banknotes" class="h-5.5 w-5.5" />
                        </span>
                    </div>

                    @if (! empty($finance['composition']))
                        <div class="relative mt-6">
                            <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-white/20">
                                @foreach ($finance['composition'] as $seg)
                                    @php($pct = $finance['composition_total'] > 0 ? round($seg['value'] / $finance['composition_total'] * 100, 1) : 0)
                                    <div class="h-full bg-white/90 first:rounded-l-full last:rounded-r-full" style="width: {{ $pct }}%; opacity: {{ 0.45 + 0.55 * ($loop->count - $loop->index) / $loop->count }}" title="{{ $seg['label'] }} — {{ $pct }}%"></div>
                                @endforeach
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-white/85">
                                @foreach ($finance['composition'] as $seg)
                                    @php($pct = $finance['composition_total'] > 0 ? round($seg['value'] / $finance['composition_total'] * 100) : 0)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-1.5 w-1.5 rounded-full bg-white/80" style="opacity: {{ 0.45 + 0.55 * ($loop->count - $loop->index) / $loop->count }}"></span>
                                        {{ $seg['label'] }} <span class="font-semibold tabular-nums">{{ $pct }}%</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="relative mt-auto grid grid-cols-3 gap-px overflow-hidden rounded-xl bg-white/10 pt-px">
                        <div class="bg-transparent px-4 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-white/70">Bln Ini</p>
                            <p class="mt-1 truncate text-sm font-bold tabular-nums">{{ $rupiah($finance['this_month']) }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-white/70">Penyimpan</p>
                            <p class="mt-1 text-sm font-bold tabular-nums">{{ number_format($finance['savers_count'], 0, ',', '.') }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-white/70">Transaksi</p>
                            <p class="mt-1 text-sm font-bold tabular-nums">{{ number_format($finance['deposits_count'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Pinjaman --}}
            @if ($canLoan)
                <div class="relative flex flex-col overflow-hidden rounded-3xl border border-border bg-surface p-6 shadow-sm sm:p-7">
                    <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-primary/5 blur-3xl"></div>
                    <div class="relative flex items-start justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted">Sisa Pokok Berjalan</p>
                            <p class="mt-2 text-4xl font-bold tracking-tight tabular-nums text-text">{{ $rupiah($loans['outstanding']) }}</p>
                            <p class="mt-1 text-xs text-muted">
                                {{ number_format($loans['active'], 0, ',', '.') }} pinjaman aktif · cair bln ini {{ $rupiah($loans['disbursed_this_month']) }}
                            </p>
                        </div>
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                            <x-ui.icon name="receipt-percent" class="h-5.5 w-5.5" />
                        </span>
                    </div>

                    <div class="relative mt-auto grid grid-cols-3 gap-3 pt-6">
                        <div class="rounded-xl bg-bg/60 px-3 py-3 ring-1 ring-inset ring-border">
                            <p class="flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="arrow-trending-up" class="h-3 w-3" /> Aktif
                            </p>
                            <p class="mt-1 text-xl font-bold tabular-nums text-text">{{ number_format($loans['active'], 0, ',', '.') }}</p>
                        </div>

                        <a href="{{ route('loans.index', ['arrears' => 'overdue']) }}" wire:navigate
                           @class([
                               'rounded-xl px-3 py-3 ring-1 ring-inset transition',
                               'bg-danger/5 ring-danger/20 hover:bg-danger/10' => $loans['overdue'] > 0,
                               'bg-bg/60 ring-border hover:bg-bg' => $loans['overdue'] === 0,
                           ])>
                            <p class="flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide {{ $loans['overdue'] > 0 ? 'text-danger' : 'text-muted' }}">
                                <x-ui.icon name="exclamation-triangle" class="h-3 w-3" /> Tunggakan
                            </p>
                            <p class="mt-1 text-xl font-bold tabular-nums {{ $loans['overdue'] > 0 ? 'text-danger' : 'text-text' }}">{{ number_format($loans['overdue'], 0, ',', '.') }}</p>
                        </a>

                        <div class="rounded-xl bg-bg/60 px-3 py-3 ring-1 ring-inset ring-border">
                            <p class="flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="clock" class="h-3 w-3" /> ≤ 7 Hari
                            </p>
                            <p class="mt-1 text-xl font-bold tabular-nums text-text">{{ number_format($loans['due_soon'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- KPI rail (minimalis) --}}
    @if ($canMembers || $canLoan || $canFinance)
        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @if ($canMembers)
                <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:shadow-md">
                    <div class="flex items-center gap-2 text-muted">
                        <x-ui.icon name="users" class="h-4 w-4" />
                        <p class="text-xs font-medium uppercase tracking-wide">Anggota Aktif</p>
                    </div>
                    <p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($members['active'], 0, ',', '.') }}</p>
                    <p class="mt-0.5 text-xs text-muted">dari {{ number_format($members['total'], 0, ',', '.') }} total</p>
                </div>

                <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:shadow-md">
                    <div class="flex items-center gap-2 text-muted">
                        <x-ui.icon name="sparkles" class="h-4 w-4" />
                        <p class="text-xs font-medium uppercase tracking-wide">Anggota Baru</p>
                    </div>
                    <p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($members['new_this_month'], 0, ',', '.') }}</p>
                    <p class="mt-0.5 text-xs text-muted">terdaftar bulan ini</p>
                </div>
            @endif

            @if ($canLoan)
                <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:shadow-md">
                    <div class="flex items-center gap-2 text-success">
                        <x-ui.icon name="check" class="h-4 w-4" />
                        <p class="text-xs font-medium uppercase tracking-wide text-muted">Pinjaman Lunas</p>
                    </div>
                    <p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($loans['settled'], 0, ',', '.') }}</p>
                    <p class="mt-0.5 text-xs text-muted">total selesai</p>
                </div>
            @endif

            @if ($canFinance)
                <a href="{{ route('savings.withdrawals') }}" wire:navigate
                   class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:border-warning/40 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-warning">
                            <x-ui.icon name="arrow-up-tray" class="h-4 w-4" />
                            <p class="text-xs font-medium uppercase tracking-wide text-muted">Pencairan Menunggu</p>
                        </div>
                        @if ($finance['pending_withdrawals'] > 0)
                            <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-warning/60"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-warning"></span></span>
                        @endif
                    </div>
                    <p class="mt-3 text-2xl font-bold tabular-nums">{{ number_format($finance['pending_withdrawals'], 0, ',', '.') }}</p>
                    <p class="mt-0.5 text-xs text-muted">perlu ditinjau</p>
                </a>
            @endif
        </section>
    @endif

    {{-- Aktivitas terbaru + aksi cepat --}}
    @if ($canFinance)
        <section class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card title="Setoran Terbaru" subtitle="4 transaksi paling akhir">
                    <x-slot:actions>
                        <x-ui.button variant="ghost" :href="route('savings.deposits')" wire:navigate class="h-9 px-3">Lihat semua</x-ui.button>
                    </x-slot:actions>

                    @if ($recent->isEmpty())
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-primary/10 text-primary">
                                <x-ui.icon name="banknotes" class="h-6 w-6" />
                            </div>
                            <h4 class="mt-3 text-sm font-semibold">Belum ada setoran</h4>
                            <p class="mt-1 max-w-xs text-xs text-muted">Setoran simpanan yang tercatat akan muncul di sini.</p>
                        </div>
                    @else
                        <div class="-mx-2 divide-y divide-border">
                            @foreach ($recent as $tx)
                                <a href="{{ route('savings.deposits.show', $tx) }}" wire:navigate
                                   class="flex items-center gap-3 rounded-xl px-2 py-3 transition hover:bg-bg/70">
                                    <span @class([
                                        'grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-semibold',
                                        'bg-danger/10 text-danger' => $tx->is_reversal,
                                        'bg-primary/10 text-primary' => ! $tx->is_reversal,
                                    ])>
                                        {{ \Illuminate\Support\Str::of($tx->member?->full_name ?? '?')->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('') }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ $tx->member?->full_name ?? 'Anggota dihapus' }}</p>
                                        <p class="truncate text-xs text-muted">
                                            {{ $this->typeLabel($tx->savings_type) }} · {{ $tx->deposit_date?->translatedFormat('d M Y') }}
                                        </p>
                                    </div>
                                    @if ($tx->is_reversal)
                                        <x-ui.badge color="danger">Reversal</x-ui.badge>
                                    @endif
                                    <span @class([
                                        'shrink-0 text-sm font-semibold tabular-nums',
                                        'text-danger' => $tx->is_reversal,
                                    ])>
                                        {{ $tx->is_reversal ? '−' : '+' }}{{ $rupiah($tx->amount) }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            </div>

            @include('livewire.partials.dashboard-quick-actions')
        </section>
    @elseif ($canLoan)
        <section class="max-w-md">
            @include('livewire.partials.dashboard-quick-actions')
        </section>
    @endif
</div>
