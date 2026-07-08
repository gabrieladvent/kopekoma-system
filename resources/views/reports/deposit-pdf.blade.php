<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('reports._styles')
</head>
<body>
    @include('reports._letterhead')

    <p class="report-title">{{ $title }}</p>
    <p class="report-sub">{{ $subtitle }}</p>

    <table class="data">
        <thead>
            <tr>
                <th>Tgl Setor</th>
                <th>Periode</th>
                <th>No. Transaksi</th>
                <th>Jenis</th>
                <th>Metode</th>
                <th>Reversal</th>
                <th class="num">Nominal (net)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($groups as $group)
                <tr class="group-head">
                    <td colspan="7">OPD: {{ $group['agency'] }}</td>
                </tr>
                @foreach ($group['members'] as $member)
                    <tr class="member-head">
                        <td colspan="7">{{ $member['number'] ?? '—' }} — {{ $member['name'] ?? '—' }}</td>
                    </tr>
                    @foreach ($member['rows'] as $row)
                        <tr @class(['reversal' => $row->is_reversal])>
                            <td>{{ optional($row->deposit_date)->format('d/m/Y') }}</td>
                            <td>{{ optional($row->period_month)->format('m/Y') }}</td>
                            <td>{{ $row->transaction_number }}</td>
                            <td>{{ $savingsTypeLabel($row->savings_type) }}</td>
                            <td>{{ $depositMethodLabel($row->deposit_method) }}</td>
                            <td>{{ $row->is_reversal ? 'Ya' : 'Tidak' }}</td>
                            <td class="num">{{ number_format((float) $row->signed_amount, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal">
                        <td colspan="6">Subtotal {{ $member['name'] ?? '—' }}</td>
                        <td class="num">{{ number_format((float) $member['subtotal'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="group-subtotal">
                    <td colspan="6">Subtotal OPD {{ $group['agency'] }}</td>
                    <td class="num">{{ number_format((float) $group['subtotal'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="empty">Tidak ada data pada periode & filter ini.</td>
                </tr>
            @endforelse
            @if (! empty($groups))
                <tr class="grand">
                    <td colspan="6">GRAND TOTAL (net)</td>
                    <td class="num">{{ number_format((float) $grandTotal, 0, ',', '.') }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @include('reports._signature')

    <div class="foot">Dicetak {{ $generatedAt->format('d/m/Y H:i') }} — angka net of reversal (terbayar − pembalikan). Baris merah = reversal.</div>
</body>
</html>
