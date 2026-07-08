<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
                Tampilkan
            </x-filament::button>
        </div>
    </form>

    @if ($this->appliedFilters !== null)
        @php($rows = $this->rows)
        @if (($this->appliedFilters['basis'] ?? null) === \App\Services\DepositReportService::BASIS_PERIOD_MONTH)
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Basis <strong>Periode Potong Gaji</strong>: setoran tanpa periode (mis. sukarela / hari raya) tidak
                    ikut ditampilkan. Untuk jenis tersebut gunakan basis <strong>Tanggal Setor</strong>.
                </p>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Hasil Laporan</x-slot>
            <x-slot name="description">{{ $rows->count() }} baris (termasuk reversal). Nominal = net of reversals.</x-slot>

            @if ($rows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada data untuk filter ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                                <th class="py-2 pr-3">Tanggal</th>
                                <th class="py-2 pr-3">No. Transaksi</th>
                                <th class="py-2 pr-3">No. Anggota</th>
                                <th class="py-2 pr-3">Nama</th>
                                <th class="py-2 pr-3">OPD</th>
                                <th class="py-2 pr-3">Jenis</th>
                                <th class="py-2 pr-3">Metode</th>
                                <th class="py-2 pr-3 text-right">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr @class([
                                    'border-b border-gray-100 dark:border-white/5',
                                    'text-danger-600 dark:text-danger-400' => $row->is_reversal,
                                ])>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ optional($row->deposit_date)->format('d/m/Y') }}</td>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $row->transaction_number }}</td>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $row->member?->member_number }}</td>
                                    <td class="py-2 pr-3">{{ $row->member?->full_name }}</td>
                                    <td class="py-2 pr-3">{{ $row->member?->agency?->agency_name ?? '-' }}</td>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $this->savingsTypeLabel($row->savings_type) }}</td>
                                    <td class="py-2 pr-3 whitespace-nowrap">
                                        {{ $this->depositMethodLabel($row->deposit_method) }}
                                        @if ($row->is_reversal)
                                            <span class="text-xs">(reversal)</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                        Rp {{ number_format((float) $row->signed_amount, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 dark:border-white/20 font-semibold">
                                <td class="py-2 pr-3" colspan="7">Grand Total (net)</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap tabular-nums">
                                    Rp {{ number_format((float) $this->total, 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
