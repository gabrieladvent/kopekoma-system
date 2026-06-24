@php
    /** @var \App\Models\Member $record */
    $record = $getRecord();
    $rows = app(\App\Services\SavingsMutationService::class)->ledgerFor($record);
    $fmt = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

    $totalMasuk = '0';
    $totalKeluar = '0';
    foreach ($rows as $r) {
        $totalMasuk = bcadd($totalMasuk, $r['masuk'], 2);
        $totalKeluar = bcadd($totalKeluar, $r['keluar'], 2);
    }
    $saldoSaatIni = app(\App\Services\SavingsBalanceService::class)->totalBalance($record);
@endphp

<div class="overflow-x-auto">
    @if (empty($rows))
        <p class="text-sm text-gray-500 dark:text-gray-400 py-4">
            Belum ada mutasi. Setoran & pencairan (cair) anggota ini akan tampil di sini.
        </p>
    @else
        <table class="w-full text-sm text-left border-collapse">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10 text-gray-500 dark:text-gray-400">
                    <th class="py-2 pr-3 font-medium">Tanggal</th>
                    <th class="py-2 pr-3 font-medium">No.</th>
                    <th class="py-2 pr-3 font-medium">Keterangan</th>
                    <th class="py-2 pr-3 font-medium">Jenis</th>
                    <th class="py-2 pr-3 font-medium text-right">Masuk</th>
                    <th class="py-2 pr-3 font-medium text-right">Keluar</th>
                    <th class="py-2 pl-3 font-medium text-right">Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-3 whitespace-nowrap">
                            {{ $row['date']->format('d M Y') }}
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['recorded_at']->format('H.i') }} WIB</span>
                        </td>
                        <td class="py-2 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $row['number'] }}</td>
                        <td class="py-2 pr-3">
                            {{ $row['description'] }}
                            @if ($row['is_reversal'])
                                <span class="text-xs text-danger-600 dark:text-danger-400">(reversal)</span>
                            @endif
                        </td>
                        <td class="py-2 pr-3 whitespace-nowrap">{{ $row['type_label'] }}</td>
                        <td class="py-2 pr-3 text-right whitespace-nowrap text-success-600 dark:text-success-400">
                            {{ bccomp($row['masuk'], '0', 2) > 0 ? $fmt($row['masuk']) : '—' }}
                        </td>
                        <td class="py-2 pr-3 text-right whitespace-nowrap text-danger-600 dark:text-danger-400">
                            {{ bccomp($row['keluar'], '0', 2) > 0 ? $fmt($row['keluar']) : '—' }}
                        </td>
                        <td class="py-2 pl-3 text-right whitespace-nowrap font-semibold">{{ $fmt($row['saldo']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-200 dark:border-white/10 font-semibold">
                    <td class="py-2 pr-3" colspan="4">Total</td>
                    <td class="py-2 pr-3 text-right whitespace-nowrap text-success-600 dark:text-success-400">{{ $fmt($totalMasuk) }}</td>
                    <td class="py-2 pr-3 text-right whitespace-nowrap text-danger-600 dark:text-danger-400">{{ $fmt($totalKeluar) }}</td>
                    <td class="py-2 pl-3 text-right whitespace-nowrap">{{ $fmt($saldoSaatIni) }}</td>
                </tr>
            </tfoot>
        </table>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Saldo berjalan = total seluruh jenis simpanan. Pencairan berstatus draft/disetujui belum tampil
            karena dana belum keluar.
        </p>
    @endif
</div>
