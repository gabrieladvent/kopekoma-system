<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-secondary/15 text-secondary">
            <x-ui.icon name="wallet-stack" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">Laporan Titipan Pokok</h2>
            <p class="mt-0.5 text-sm text-muted">
                Kelebihan bayar yang mengendap dan memotong pokok angsuran berikutnya. Satu baris per pinjaman berjalan —
                titipan melekat pada pinjaman, bukan pada anggota.
            </p>
        </div>
    </div>

    {{-- Ringkasan --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.stat label="Total Titipan Pokok Mengendap" value="Rp {{ number_format((float) $total, 0, ',', '.') }}">
            <x-slot:foot>Uang anggota yang belum terpakai, tersebar di {{ count($rows) }} pinjaman berjalan.</x-slot:foot>
        </x-ui.stat>
        <x-ui.stat label="Pinjaman Bertitipan" value="{{ count($rows) }}">
            <x-slot:foot>Dari seluruh pinjaman berstatus Cair.</x-slot:foot>
        </x-ui.stat>
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative w-full lg:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama, no. anggota, atau no. pinjaman…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="flex items-center gap-2">
            <select wire:model.live="agency"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua OPD</option>
                @foreach ($agencyOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
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
                        <th class="px-5 py-3 text-left">Anggota</th>
                        <th class="px-5 py-3 text-left">OPD</th>
                        <th class="px-5 py-3 text-left">Pinjaman</th>
                        <th class="px-5 py-3 text-right">Titipan Pokok</th>
                        <th class="px-5 py-3 text-right">Tagihan Kontrak</th>
                        <th class="px-5 py-3 text-right">Tagihan Efektif</th>
                        <th class="w-20 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rows as $row)
                        <tr class="transition hover:bg-bg/60">
                            <td class="px-5 py-3">
                                <div class="font-medium text-text">{{ $row['member_name'] }}</div>
                                <div class="text-xs text-muted">{{ $row['member_number'] }}</div>
                            </td>
                            <td class="px-5 py-3 text-muted">{{ $row['agency'] }}</td>
                            <td class="px-5 py-3">
                                <div class="text-text">{{ $row['loan_number'] }}</div>
                                @if ($row['next_seq'])
                                    <div class="text-xs text-muted">Angsuran berikutnya #{{ $row['next_seq'] }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right font-semibold tabular-nums text-text">
                                Rp {{ number_format((float) $row['credit'], 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-muted">
                                {{ $row['contract_bill'] === null ? '—' : 'Rp '.number_format((float) $row['contract_bill'], 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-text">
                                {{ $row['effective_bill'] === null ? '—' : 'Rp '.number_format((float) $row['effective_bill'], 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a href="{{ route('loans.show', $row['loan_id']) }}" wire:navigate
                                   class="inline-flex items-center gap-1 text-sm font-medium text-primary transition hover:underline">
                                    Riwayat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-sm text-muted">
                                @if ($this->hasActiveFilters())
                                    Tak ada pinjaman bertitipan yang cocok dengan filter ini.
                                @else
                                    Belum ada Titipan Pokok yang mengendap.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{--
        Dibaca berdampingan dengan sengaja: selisih Tagihan Kontrak − Tagihan Efektif
        adalah persis nominal yang boleh dipotong dari tagihan anggota bulan ini, dan
        karena itu juga persis nominal yang bisa dikantongi bila petugas menerima uang
        sebesar kontrak tapi mencatat yang efektif (R14). Angka besar yang tak berubah
        berbulan-bulan patut ditanyakan.
    --}}
    <p class="text-xs text-muted">
        Titipan Pokok hanya memotong komponen <span class="font-medium text-text">pokok</span>, tak pernah jasa maupun
        tabungan berjangka — karena itu tagihan efektif tak pernah nol. Sisa titipan saat pinjaman ditutup dilimpahkan
        ke Simpanan Sukarela anggota.
    </p>
</div>
