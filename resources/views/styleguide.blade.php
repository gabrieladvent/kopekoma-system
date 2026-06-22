<x-layouts.app title="Design System">
    <div class="space-y-8">
        {{-- Bento utama: hero gradient (signature) + stat cards beragam ukuran --}}
        <section class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {{-- Spotlight hero --}}
            <div class="bg-brand-gradient relative overflow-hidden rounded-2xl p-6 text-white shadow-sm lg:col-span-2 lg:row-span-2">
                <div class="pointer-events-none absolute -right-10 -top-10 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
                <div class="pointer-events-none absolute -bottom-16 -left-8 h-56 w-56 rounded-full bg-black/10 blur-2xl"></div>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">Selamat datang kembali, Gabriel</p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight">Design System KOPEKOMA</h2>
                    <p class="mt-2 max-w-md text-sm text-white/80">
                        Acuan visual komponen Livewire. Coba toggle tema di kanan atas. Semua warna pakai token —
                        ubah <code class="rounded bg-white/15 px-1">--color-primary</code> dan seluruh tampilan ikut berubah.
                    </p>
                    <div class="mt-8">
                        <p class="text-sm font-medium text-white/70">Total Simpanan Terkumpul</p>
                        <p class="mt-1 text-4xl font-bold tracking-tight tabular-nums">Rp 1.254.300.000</p>
                        <p class="mt-1 text-xs text-white/70">↑ 12% dibanding bulan lalu</p>
                    </div>
                </div>
            </div>

            <x-ui.stat label="Anggota Aktif" value="342" delta="+8" />
            <x-ui.stat label="Tunggakan" value="Rp 18,4 Jt" delta="-3%" :deltaUp="false" />
        </section>

        <div>
            <h3 class="text-lg font-semibold tracking-tight">Komponen</h3>
            <p class="mt-1 text-sm text-muted">Anatomi baku — pakai ulang via <code class="rounded bg-border/60 px-1">&lt;x-ui.*&gt;</code>.</p>
        </div>

        {{-- Buttons --}}
        <x-ui.card title="Tombol" subtitle="primary / secondary / ghost / danger">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button>Simpan</x-ui.button>
                <x-ui.button variant="secondary">Detail</x-ui.button>
                <x-ui.button variant="ghost">Batal</x-ui.button>
                <x-ui.button variant="danger">Hapus</x-ui.button>
                <x-ui.button disabled>Disabled</x-ui.button>
            </div>
        </x-ui.card>

        {{-- Form --}}
        <x-ui.card title="Form" subtitle="label di atas, focus ring primary">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Nama Anggota" name="nama" placeholder="cth. Budi Santoso" hint="Sesuai KTP" />
                <x-ui.input label="NIP" name="nip" placeholder="0000000000" error="NIP wajib diisi." />
            </div>
        </x-ui.card>

        {{-- Badges --}}
        <x-ui.card title="Status Pill">
            <div class="flex flex-wrap gap-2">
                <x-ui.badge color="success">Lunas</x-ui.badge>
                <x-ui.badge color="warning">Tertunggak</x-ui.badge>
                <x-ui.badge color="danger">Macet</x-ui.badge>
                <x-ui.badge color="primary">Aktif</x-ui.badge>
                <x-ui.badge>Nonaktif</x-ui.badge>
            </div>
        </x-ui.card>

        {{-- Table --}}
        <x-ui.card title="Tabel" subtitle="nominal rata kanan, tabular-nums">
            <div class="overflow-hidden rounded-xl border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-4 py-3 text-left">Anggota</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([['Budi Santoso', 'success', 'Lunas', 'Rp 12.500.000'], ['Siti Aminah', 'warning', 'Tertunggak', 'Rp 3.200.000'], ['Joko Widodo', 'success', 'Lunas', 'Rp 8.750.000']] as [$nama, $c, $st, $saldo])
                            <tr class="border-t border-border transition hover:bg-bg/60">
                                <td class="px-4 py-3 font-medium">{{ $nama }}</td>
                                <td class="px-4 py-3"><x-ui.badge :color="$c">{{ $st }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $saldo }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Modal --}}
        <x-ui.card title="Modal" subtitle="Alpine x-transition, tutup via Esc / klik overlay">
            <div x-data="{ open: false }">
                <x-ui.button @click="open = true">Buka Modal</x-ui.button>
                <x-ui.modal title="Konfirmasi">
                    <p class="text-sm text-muted">Yakin ingin melanjutkan tindakan ini?</p>
                    <div class="mt-6 flex justify-end gap-3">
                        <x-ui.button variant="ghost" @click="open = false">Batal</x-ui.button>
                        <x-ui.button @click="open = false">Ya, lanjut</x-ui.button>
                    </div>
                </x-ui.modal>
            </div>
        </x-ui.card>

        {{-- Empty state --}}
        <x-ui.card title="Empty State">
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <div class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h4 class="mt-3 text-sm font-semibold">Belum ada data</h4>
                <p class="mt-1 max-w-xs text-xs text-muted">Mulai dengan menambahkan anggota pertama koperasi Anda.</p>
                <x-ui.button class="mt-4">Tambah Anggota</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
