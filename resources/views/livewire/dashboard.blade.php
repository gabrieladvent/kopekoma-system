<div class="space-y-8">
    {{-- Bento utama --}}
    <section class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="bg-brand-gradient relative overflow-hidden rounded-2xl p-6 text-white shadow-sm lg:col-span-2">
            <div class="pointer-events-none absolute -right-10 -top-10 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="relative">
                <p class="text-sm font-medium text-white/80">Selamat datang kembali, {{ auth()->user()->name }}</p>
                <p class="mt-6 text-sm font-medium text-white/70">Total Simpanan Terkumpul</p>
                <p class="mt-1 text-4xl font-bold tracking-tight tabular-nums">Rp 1.254.300.000</p>
                <p class="mt-1 text-xs text-white/70">↑ 12% dibanding bulan lalu</p>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-1">
            <x-ui.stat label="Anggota Aktif" value="342" delta="+8" />
            <x-ui.stat label="Tunggakan" value="Rp 18,4 Jt" delta="-3%" :deltaUp="false" />
        </div>
    </section>

    {{-- Transaksi terbaru --}}
    <x-ui.card title="Transaksi Terbaru" subtitle="5 aktivitas terakhir">
        <x-slot:actions>
            <x-ui.button variant="ghost" class="h-9 px-3">Lihat semua</x-ui.button>
        </x-slot:actions>

        <div class="overflow-hidden rounded-xl border border-border">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-4 py-3 text-left">Anggota</th>
                        <th class="px-4 py-3 text-left">Jenis</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ([
                        ['Budi Santoso', 'Setoran Simpanan', 'success', 'Lunas', 'Rp 500.000'],
                        ['Siti Aminah', 'Angsuran Pinjaman', 'warning', 'Tertunggak', 'Rp 1.200.000'],
                        ['Joko Widodo', 'Setoran Simpanan', 'success', 'Lunas', 'Rp 750.000'],
                        ['Dewi Lestari', 'Pencairan', 'primary', 'Diproses', 'Rp 3.000.000'],
                    ] as [$nama, $jenis, $c, $st, $jml])
                        <tr class="border-t border-border transition hover:bg-bg/60">
                            <td class="px-4 py-3 font-medium">{{ $nama }}</td>
                            <td class="px-4 py-3 text-muted">{{ $jenis }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$c">{{ $st }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $jml }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
