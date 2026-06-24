<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-secondary/15 text-secondary">
            <x-ui.icon name="wallet-stack" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">Saldo Anggota</h2>
            <p class="mt-0.5 text-sm text-muted">Rekap saldo simpanan tiap anggota. Saldo dikelola lewat transaksi setoran/penarikan.</p>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative w-full lg:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau no. anggota…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:flex lg:items-center">
            <select wire:model.live="agency"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua OPD</option>
                @foreach ($agencyOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <select wire:model.live="grade"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua Golongan</option>
                @foreach ($gradeOptions as $g)
                    <option value="{{ $g->id }}">{{ $g->code }} — {{ $g->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="status"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Status</option>
                <option value="Aktif">Aktif</option>
                <option value="Non-Aktif">Non-Aktif</option>
                <option value="Keluar">Keluar</option>
                <option value="Meninggal">Meninggal</option>
            </select>

            @if ($this->hasActiveFilters())
                <button type="button" wire:click="clearFilters"
                        class="inline-flex h-10 shrink-0 items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-medium text-danger transition hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none">
                    <x-ui.icon name="x" class="h-4 w-4" /> Bersihkan
                </button>
            @endif
        </div>
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="sticky left-0 z-10 bg-bg px-5 py-3 text-left">Anggota</th>
                        <th class="px-5 py-3 text-left">OPD / Gol.</th>
                        <th class="px-5 py-3 text-right">Pokok</th>
                        <th class="px-5 py-3 text-right">Wajib</th>
                        <th class="px-5 py-3 text-right">Sukarela</th>
                        <th class="px-5 py-3 text-right">Hari Raya</th>
                        <th class="px-5 py-3 text-right">Wajib Belanja</th>
                        <th class="px-5 py-3 text-right">Total</th>
                        <th class="w-20 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 6; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-4 w-36 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-28 animate-pulse rounded bg-border/60"></div></td>
                            @for ($c = 0; $c < 6; $c++)
                                <td class="px-5 py-4"><div class="ml-auto h-4 w-16 animate-pulse rounded bg-border/60"></div></td>
                            @endfor
                            <td class="px-5 py-4"><div class="ml-auto h-4 w-12 animate-pulse rounded bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($rows as $row)
                        @php($member = $row['member'])
                        <tr class="transition hover:bg-bg/60" wire:key="bal-{{ $member->id }}">
                            <td class="sticky left-0 z-10 bg-surface px-5 py-4">
                                <a href="{{ route('savings.balances.show', $member) }}" wire:navigate class="block font-medium text-text hover:text-primary">{{ $member->full_name }}</a>
                                <span class="font-mono text-xs text-muted">{{ $member->member_number }}</span>
                            </td>
                            <td class="px-5 py-4">
                                <span class="block text-text">{{ $member->agency?->agency_name ?? '—' }}</span>
                                <span class="text-xs text-muted">Gol. {{ $member->grade?->code ?? '—' }}</span>
                            </td>
                            <td class="px-5 py-4 text-right tabular-nums text-text">{{ number_format((float) $row['pokok'], 0, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right tabular-nums text-text">{{ number_format((float) $row['wajib'], 0, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right tabular-nums text-text">{{ number_format((float) $row['sukarela'], 0, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right tabular-nums text-text">{{ number_format((float) $row['hari_raya'], 0, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right tabular-nums text-text">{{ number_format((float) $row['wajib_belanja'], 0, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right font-bold tabular-nums text-success">Rp {{ number_format((float) $row['total'], 0, ',', '.') }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('savings.balances.show', $member) }}" wire:navigate
                                   class="inline-flex items-center gap-1 text-sm font-medium text-primary transition hover:underline">
                                    <x-ui.icon name="receipt" class="h-4 w-4" /> Mutasi
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-secondary/15 text-secondary">
                                        <x-ui.icon name="wallet-stack" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $this->hasActiveFilters() ? 'Tidak ada anggota yang cocok' : 'Belum ada data anggota' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $this->hasActiveFilters() ? 'Coba ubah kata kunci atau filter.' : 'Saldo akan tampil setelah ada anggota terdaftar.' }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($members->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $members->links() }}
            </div>
        @endif
    </x-ui.card>

    <x-ui.toast-host />
</div>
