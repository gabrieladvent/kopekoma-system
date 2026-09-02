<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-secondary/15 text-secondary">
            <x-ui.icon name="shield" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">Rekonsiliasi Simpanan Pinjaman</h2>
            <p class="mt-0.5 text-sm text-muted">
                Membandingkan saldo SWP &amp; Tabungan Berjangka yang tercatat dengan yang seharusnya menurut data
                pinjaman. Hanya menampilkan yang tidak cocok.
            </p>
        </div>
    </div>

    @if ($rows === [])
        <x-ui.card>
            <div class="flex items-start gap-3 py-6">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-success/15 text-success">
                    <x-ui.icon name="check" class="h-5 w-5" />
                </span>
                <div>
                    <p class="font-medium text-text">Seluruhnya cocok</p>
                    <p class="mt-1 text-sm text-muted">
                        {{ number_format($memberCount, 0, ',', '.') }} anggota diperiksa. Saldo SWP dan Tabungan
                        Berjangka setiap anggota sama persis dengan data pinjamannya.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @else
        <x-ui.card class="p-0">
            <div class="border-b border-border bg-danger/5 px-6 py-4">
                <p class="text-sm font-semibold text-danger">
                    {{ count($rows) }} anggota tidak cocok — periksa satu per satu.
                </p>
                <p class="mt-1 text-xs text-muted">
                    Selisih <span class="font-medium">positif</span> = saldo tercatat lebih besar dari yang seharusnya
                    (kemungkinan setoran yang tak berdasar). Selisih <span class="font-medium">negatif</span> = saldo
                    lebih kecil (kemungkinan pembalikan yang tak semestinya, atau penarikan yang belum terhitung).
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                        <tr>
                            <th rowspan="2" class="px-5 py-3 text-left align-bottom">Anggota</th>
                            <th colspan="3" class="border-l border-border px-5 py-2 text-center">SWP</th>
                            <th colspan="3" class="border-l border-border px-5 py-2 text-center">Tabungan Berjangka</th>
                        </tr>
                        <tr>
                            <th class="border-l border-border px-5 py-2 text-right">Tercatat</th>
                            <th class="px-5 py-2 text-right">Seharusnya</th>
                            <th class="px-5 py-2 text-right">Selisih</th>
                            <th class="border-l border-border px-5 py-2 text-right">Tercatat</th>
                            <th class="px-5 py-2 text-right">Seharusnya</th>
                            <th class="px-5 py-2 text-right">Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($rows as $row)
                            <tr class="transition hover:bg-bg/60">
                                <td class="px-5 py-3">
                                    <div class="font-medium text-text">{{ $row['member_name'] }}</div>
                                    <div class="text-xs text-muted">{{ $row['member_number'] }}</div>
                                </td>
                                @foreach (['swp', 'tabungan_berjangka'] as $type)
                                    @php($cell = $row[$type])
                                    <td class="border-l border-border px-5 py-3 text-right tabular-nums text-muted">
                                        {{ number_format((float) $cell['tercatat'], 0, ',', '.') }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-muted">
                                        {{ number_format((float) $cell['seharusnya'], 0, ',', '.') }}
                                    </td>
                                    <td @class([
                                        'px-5 py-3 text-right font-semibold tabular-nums',
                                        'text-muted' => $cell['selisih'] === '0.00',
                                        'text-danger' => $cell['selisih'] !== '0.00',
                                    ])>
                                        {{ $cell['selisih'] === '0.00' ? '—' : number_format((float) $cell['selisih'], 0, ',', '.') }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{--
        Kenapa halaman ini ada: sebelum SWP & Tabungan Berjangka jadi simpanan
        sungguhan, saldonya diturunkan dari tabel pinjaman — bentuk yang tak bisa
        dipalsukan. Sekarang saldonya baris setoran biasa, jadi pembandingnya harus
        dihitung terpisah. Halaman kosong adalah hasil yang benar.
    --}}
    <p class="text-xs text-muted">
        <span class="font-medium text-text">Seharusnya</span> dihitung ulang dari data pinjaman: SWP = jumlah
        <code>swp_amount</code> seluruh pinjaman yang tidak dibatalkan; Tabungan Berjangka = tarif bulanan × jumlah
        angsuran terbayar (bersih setelah pembatalan, baris pelunasan tidak dihitung).
    </p>
</div>
