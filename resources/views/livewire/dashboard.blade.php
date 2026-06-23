@php
    // Palet segmen komposisi — semua token brand, bukan warna mentah.
    $segColors = [
        'pokok' => 'bg-primary',
        'wajib' => 'bg-secondary',
        'hari_raya' => 'bg-warning',
        'wajib_belanja' => 'bg-success',
        'sukarela' => 'bg-primary/40',
    ];
    $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
@endphp

<div class="space-y-6">
    {{-- Sapaan + aksi cepat --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-muted">{{ \Illuminate\Support\Carbon::now()->translatedFormat('l, d F Y') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight">{{ $greeting }}, {{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first() }} 👋</h2>
        </div>
        @if ($canFinance)
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button :href="route('savings.deposits.create')" wire:navigate class="h-9 px-3.5">
                    <x-ui.icon name="plus" class="h-4 w-4" /> Setor Simpanan
                </x-ui.button>
                <x-ui.button variant="ghost" :href="route('savings.balances')" wire:navigate class="h-9 px-3.5">
                    <x-ui.icon name="wallet-stack" class="h-4 w-4" /> Saldo Anggota
                </x-ui.button>
            </div>
        @endif
    </div>

    @if (! $canFinance && ! $canMembers)
        {{-- Tak punya akses ringkasan apa pun --}}
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

    @if ($canFinance)
        {{-- Bento utama: hero saldo + stat sekunder --}}
        <section class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {{-- Hero saldo (signature gradient + komposisi inline) --}}
            <div class="bg-brand-gradient relative flex flex-col overflow-hidden rounded-2xl p-6 text-white shadow-sm lg:col-span-2">
                <div class="pointer-events-none absolute -right-12 -top-12 h-52 w-52 rounded-full bg-white/10 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-20 left-1/3 h-56 w-56 rounded-full bg-black/10 blur-3xl"></div>

                <div class="relative flex items-start justify-between">
                    <div>
                        <p class="inline-flex items-center gap-1.5 text-sm font-medium text-white/80">
                            Total Simpanan Terkumpul
                            @if ($finance['this_month_delta'] !== null)
                                <span class="rounded-full bg-white/15 px-2 py-0.5 text-xs font-semibold backdrop-blur">
                                    {{ $finance['this_month_delta'] >= 0 ? '↑' : '↓' }} {{ abs($finance['this_month_delta']) }}% MoM
                                </span>
                            @endif
                        </p>
                        <p class="mt-2 text-4xl font-bold tracking-tight tabular-nums sm:text-[2.75rem]">{{ $rupiah($finance['total_balance']) }}</p>
                    </div>
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white/15 backdrop-blur">
                        <x-ui.icon name="banknotes" class="h-5.5 w-5.5" />
                    </span>
                </div>

                {{-- Mini-stat: isi ruang hero biar tidak kosong --}}
                <div class="relative mt-6 grid grid-cols-3 divide-x divide-white/15 rounded-xl bg-white/10 backdrop-blur">
                    <div class="px-4 py-3">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-white/70">Setoran Bln Ini</p>
                        <p class="mt-1 truncate text-sm font-bold tabular-nums">{{ $rupiah($finance['this_month']) }}</p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-white/70">Anggota Menyimpan</p>
                        <p class="mt-1 text-sm font-bold tabular-nums">{{ number_format($finance['savers_count'], 0, ',', '.') }}</p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-white/70">Transaksi</p>
                        <p class="mt-1 text-sm font-bold tabular-nums">{{ number_format($finance['deposits_count'], 0, ',', '.') }}</p>
                    </div>
                </div>

                {{-- Komposisi: segmented bar + legenda ringkas --}}
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
            </div>

            {{-- Stat sekunder --}}
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-1">
                <div class="group rounded-2xl border border-border bg-surface p-5 shadow-sm transition duration-150 ease-out hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-secondary/10 text-secondary">
                            <x-ui.icon name="arrow-down-tray" class="h-4.5 w-4.5" />
                        </span>
                        @if ($finance['this_month_delta'] !== null)
                            <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $finance['this_month_delta'] >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $finance['this_month_delta'] >= 0 ? '↑' : '↓' }} {{ abs($finance['this_month_delta']) }}%
                            </span>
                        @endif
                    </div>
                    <p class="mt-4 text-sm font-medium text-muted">Setoran Bulan Ini</p>
                    <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums">{{ $rupiah($finance['this_month']) }}</p>
                </div>

                <a href="{{ route('savings.withdrawals') }}" wire:navigate
                   class="group rounded-2xl border border-border bg-surface p-5 shadow-sm transition duration-150 ease-out hover:border-warning/40 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-warning/10 text-warning">
                            <x-ui.icon name="arrow-up-tray" class="h-4.5 w-4.5" />
                        </span>
                        @if ($finance['pending_withdrawals'] > 0)
                            <span class="flex h-2 w-2 rounded-full bg-warning"><span class="h-2 w-2 animate-ping rounded-full bg-warning/60"></span></span>
                        @endif
                    </div>
                    <p class="mt-4 text-sm font-medium text-muted">Pencairan Menunggu</p>
                    <p class="mt-1 flex items-baseline gap-2 text-2xl font-bold tracking-tight tabular-nums">
                        {{ $finance['pending_withdrawals'] }}
                        <span class="text-xs font-medium text-muted">perlu ditinjau</span>
                    </p>
                </a>
            </div>
        </section>
    @endif

    {{-- KPI keanggotaan --}}
    @if ($canMembers)
        <section class="grid grid-cols-2 gap-5 lg:grid-cols-4">
            <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:shadow-md">
                <div class="flex items-center gap-2.5 text-muted">
                    <x-ui.icon name="users" class="h-4 w-4" />
                    <p class="text-sm font-medium">Anggota Aktif</p>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums">{{ number_format($members['active'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs text-muted">dari {{ number_format($members['total'], 0, ',', '.') }} total anggota</p>
            </div>

            <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:shadow-md">
                <div class="flex items-center gap-2.5 text-muted">
                    <x-ui.icon name="sparkles" class="h-4 w-4" />
                    <p class="text-sm font-medium">Anggota Baru</p>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums">{{ number_format($members['new_this_month'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs text-muted">terdaftar bulan ini</p>
            </div>

            @if ($canFinance)
                <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:shadow-md">
                    <div class="flex items-center gap-2.5 text-muted">
                        <x-ui.icon name="wallet-stack" class="h-4 w-4" />
                        <p class="text-sm font-medium">Rata-rata / Anggota</p>
                    </div>
                    <p class="mt-3 text-2xl font-bold tracking-tight tabular-nums">
                        {{ $rupiah($members['active'] > 0 ? (float) $finance['total_balance'] / $members['active'] : 0) }}
                    </p>
                    <p class="mt-1 text-xs text-muted">saldo simpanan rata-rata</p>
                </div>

                <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm transition hover:shadow-md">
                    <div class="flex items-center gap-2.5 text-muted">
                        <x-ui.icon name="receipt" class="h-4 w-4" />
                        <p class="text-sm font-medium">Jenis Simpanan</p>
                    </div>
                    <p class="mt-3 text-3xl font-bold tracking-tight tabular-nums">{{ count($finance['composition']) }}</p>
                    <p class="mt-1 text-xs text-muted">jenis dengan saldo aktif</p>
                </div>
            @endif
        </section>
    @endif

    {{-- Transaksi terbaru + aksi cepat --}}
    @if ($canFinance)
        <section class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card title="Setoran Terbaru" subtitle="6 transaksi paling akhir">
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

            {{-- Aksi cepat --}}
            <x-ui.card title="Aksi Cepat">
                <div class="space-y-2">
                    @php($actions = [
                        ['Setor Simpanan', 'Catat setoran anggota', 'plus', 'savings.deposits.create', 'create_savings::deposit'],
                        ['Pencairan', 'Ajukan pencairan simpanan', 'arrow-up-tray', 'savings.withdrawals.create', 'create_savings::withdrawal'],
                        ['Belanja Toko', 'Catat transaksi belanja', 'shopping-cart', 'savings.shopping.create', 'create_shopping::transaction'],
                        ['Tambah Anggota', 'Daftarkan anggota baru', 'user', 'master.members.create', 'create_member'],
                    ])
                    @foreach ($actions as [$label, $desc, $icon, $route, $perm])
                        @can($perm)
                            <a href="{{ route($route) }}" wire:navigate
                               class="group flex items-center gap-3 rounded-xl border border-border p-3 transition hover:border-primary/40 hover:bg-primary/5">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary transition group-hover:scale-105">
                                    <x-ui.icon :name="$icon" class="h-4.5 w-4.5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">{{ $label }}</p>
                                    <p class="truncate text-xs text-muted">{{ $desc }}</p>
                                </div>
                                <x-ui.icon name="arrow-left" class="ml-auto h-4 w-4 rotate-180 text-muted opacity-0 transition group-hover:opacity-100" />
                            </a>
                        @endcan
                    @endforeach
                </div>
            </x-ui.card>
        </section>
    @endif
</div>
