@php($fmt = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'))
<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('savings.balances') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke rekap saldo
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-secondary/15 text-secondary">
                <x-ui.icon name="wallet-stack" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $member->member_number }}</x-ui.badge>
                    <x-ui.badge :color="$member->status === 'Aktif' ? 'success' : 'neutral'">{{ $member->status }}</x-ui.badge>
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $member->full_name }}</h2>
                <p class="text-sm text-muted">
                    {{ $member->agency?->agency_name ?? '—' }} · Gol. {{ $member->grade?->code ?? '—' }}
                </p>
            </div>
        </div>

        @can('view_member')
            <x-ui.button variant="ghost" :href="route('master.members.show', $member)" wire:navigate class="shrink-0">
                <x-ui.icon name="user" class="h-4 w-4" /> Profil Anggota
            </x-ui.button>
        @endcan
    </div>

    {{-- Total + breakdown (bento) --}}
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Total besar (signature) --}}
        <div class="rounded-2xl border border-primary/20 bg-gradient-to-br from-primary/10 to-secondary/5 p-6 shadow-sm lg:row-span-2 lg:flex lg:flex-col lg:justify-center">
            <p class="flex items-center gap-2 text-sm font-medium text-primary">
                <x-ui.icon name="wallet" class="h-4 w-4" /> Total Saldo
            </p>
            <p class="mt-2 text-4xl font-bold tracking-tight tabular-nums text-text">{{ $fmt($total) }}</p>
            <p class="mt-1 text-xs text-muted">Akumulasi seluruh jenis simpanan anggota ini.</p>

            @if (! empty($holidayByYear))
                <div class="mt-5 space-y-1.5 border-t border-primary/15 pt-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">Hari Raya per Tahun</p>
                    @foreach ($holidayByYear as $hYear => $hBalance)
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-mono text-muted">{{ $hYear }}</span>
                            <span class="tabular-nums text-text">{{ $fmt($hBalance) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Kartu per jenis --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:col-span-2">
            @foreach ($cards as $card)
                <div class="rounded-2xl border border-border bg-surface p-4 shadow-sm transition hover:shadow-md">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-bg text-muted">
                        <x-ui.icon :name="$card['icon']" class="h-4.5 w-4.5" />
                    </span>
                    <p class="mt-3 text-xs font-medium text-muted">{{ $card['label'] }}</p>
                    <p class="mt-0.5 text-lg font-bold tabular-nums text-text">{{ $fmt($card['value']) }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Buku mutasi --}}
    <x-ui.card class="p-0">
        <div class="flex flex-col gap-3 border-b border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <x-ui.icon name="receipt" class="h-5 w-5 text-primary" />
                <div>
                    <h3 class="text-sm font-semibold text-text">Buku Mutasi Simpanan</h3>
                    <p class="text-xs text-muted">Setoran, pencairan, & belanja toko — kronologis dengan saldo berjalan.</p>
                </div>
            </div>
            <select wire:model.live="type"
                    class="h-9 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Jenis</option>
                <option value="pokok">Pokok</option>
                <option value="wajib">Wajib</option>
                <option value="sukarela">Sukarela</option>
                <option value="hari_raya">Hari Raya</option>
                <option value="wajib_belanja">Wajib Belanja</option>
            </select>
        </div>

        <div class="max-h-[28rem] overflow-auto">
            <table class="w-full text-sm">
                <thead class="sticky top-0 z-10 bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-6 py-3 text-left">Tanggal</th>
                        <th class="px-6 py-3 text-left">No.</th>
                        <th class="px-6 py-3 text-left">Keterangan</th>
                        <th class="px-6 py-3 text-left">Jenis</th>
                        <th class="px-6 py-3 text-right">Masuk</th>
                        <th class="px-6 py-3 text-right">Keluar</th>
                        <th class="px-6 py-3 text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($ledger as $row)
                        <tr class="transition hover:bg-bg/60">
                            <td class="whitespace-nowrap px-6 py-3 text-text">{{ $row['date']->translatedFormat('d M Y') }}</td>
                            <td class="whitespace-nowrap px-6 py-3 font-mono text-xs text-muted">{{ $row['number'] }}</td>
                            <td class="px-6 py-3 text-text">
                                {{ $row['description'] }}
                                @if ($row['is_reversal'])
                                    <span class="ml-1 text-xs text-danger">(reversal)</span>
                                @endif
                            </td>
                            <td class="px-6 py-3">
                                <x-ui.badge color="neutral">{{ $row['type_label'] }}</x-ui.badge>
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums text-success">
                                {{ bccomp($row['masuk'], '0', 2) > 0 ? $fmt($row['masuk']) : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums text-danger">
                                {{ bccomp($row['keluar'], '0', 2) > 0 ? $fmt($row['keluar']) : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-right font-semibold tabular-nums text-text">{{ $fmt($row['saldo']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-14 text-center">
                                <div class="flex flex-col items-center">
                                    <div class="grid h-12 w-12 place-items-center rounded-2xl bg-border/60 text-muted">
                                        <x-ui.icon name="receipt" class="h-6 w-6" />
                                    </div>
                                    <p class="mt-3 text-sm text-muted">
                                        {{ $type === 'all' ? 'Belum ada mutasi simpanan untuk anggota ini.' : 'Tidak ada mutasi untuk jenis ini.' }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if (! empty($ledger))
                    <tfoot class="sticky bottom-0 bg-surface">
                        <tr class="border-t-2 border-border font-semibold">
                            <td class="px-6 py-3 text-text" colspan="4">Total</td>
                            <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums text-success">{{ $fmt($totalMasuk) }}</td>
                            <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums text-danger">{{ $fmt($totalKeluar) }}</td>
                            <td class="px-6 py-3"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-ui.card>

    <x-ui.toast-host />
</div>
