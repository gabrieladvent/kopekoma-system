<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Slip Setoran {{ $deposit->transaction_number }}</title>
    <style>
        * {
            font-family: DejaVu Sans, sans-serif;
        }

        body {
            margin: 0;
            padding: 24px;
            color: #111827;
        }

        .slip {
            width: 100%;
            max-width: 540px;
            border: 1px solid #1f2937;
            border-radius: 8px;
            padding: 20px 24px;
            box-sizing: border-box;
        }

        .header {
            border-bottom: 2px solid #1f2937;
            padding-bottom: 8px;
            margin-bottom: 14px;
            text-align: center;
        }

        .org {
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .doc-title {
            font-size: 11px;
            color: #6b7280;
            margin-top: 2px;
        }

        .number {
            font-size: 13px;
            font-weight: bold;
            color: #1d4ed8;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }

        table {
            width: 100%;
            font-size: 11px;
            border-collapse: collapse;
        }

        td {
            padding: 4px 0;
            vertical-align: top;
        }

        td.label {
            width: 36%;
            color: #6b7280;
        }

        td.sep {
            width: 4%;
        }

        .amount-row td {
            border-top: 1px solid #d1d5db;
            border-bottom: 1px solid #d1d5db;
            padding: 8px 0;
        }

        .amount {
            font-size: 15px;
            font-weight: bold;
            color: #047857;
        }

        .reversal-flag {
            margin-top: 10px;
            padding: 6px 10px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            color: #b91c1c;
            font-size: 10px;
            font-weight: bold;
        }

        .footer {
            margin-top: 18px;
            font-size: 9px;
            color: #9ca3af;
            border-top: 1px dashed #d1d5db;
            padding-top: 8px;
        }

        .sign {
            margin-top: 28px;
            font-size: 10px;
        }

        .sign td {
            text-align: center;
        }

        .sign .line {
            border-top: 1px solid #9ca3af;
            width: 60%;
            margin: 36px auto 4px;
        }
    </style>
</head>

<body>
    <div class="slip">
        <div class="header">
            <div class="org">KPRI KOPEKOMA</div>
            <div class="doc-title">Slip Bukti Setoran Simpanan</div>
        </div>

        <div class="number">{{ $deposit->transaction_number }}</div>

        <table>
            <tr>
                <td class="label">Nama Anggota</td>
                <td class="sep">:</td>
                <td>{{ $deposit->member?->full_name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">No. Anggota</td>
                <td class="sep">:</td>
                <td>{{ $deposit->member?->member_number ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Jenis Simpanan</td>
                <td class="sep">:</td>
                <td>{{ $savingsTypeLabel }}</td>
            </tr>
            <tr>
                <td class="label">Tanggal Setor</td>
                <td class="sep">:</td>
                <td>{{ optional($deposit->deposit_date)->format('d M Y') ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Periode</td>
                <td class="sep">:</td>
                <td>{{ optional($deposit->period_month)->format('F Y') ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Metode Setor</td>
                <td class="sep">:</td>
                <td>{{ $depositMethodLabel }}</td>
            </tr>
            <tr>
                <td class="label">Disetor Oleh</td>
                <td class="sep">:</td>
                <td>{{ $depositedByLabel }}</td>
            </tr>
            <tr>
                <td class="label">No. Referensi</td>
                <td class="sep">:</td>
                <td>{{ $deposit->reference_number ?? '-' }}</td>
            </tr>
            <tr class="amount-row">
                <td class="label">Nominal</td>
                <td class="sep">:</td>
                <td class="amount">Rp {{ number_format((float) $deposit->amount, 0, ',', '.') }}</td>
            </tr>
            @if ($deposit->notes)
                <tr>
                    <td class="label">Catatan</td>
                    <td class="sep">:</td>
                    <td>{{ $deposit->notes }}</td>
                </tr>
            @endif
        </table>

        @if ($deposit->is_reversal)
            <div class="reversal-flag">TRANSAKSI REVERSAL — slip ini mencatat transaksi-lawan (koreksi).</div>
        @endif

        <table class="sign">
            <tr>
                <td>
                    <div>Penyetor</div>
                    <div class="line"></div>
                    <div>{{ $deposit->member?->full_name ?? '' }}</div>
                </td>
                <td>
                    <div>Petugas</div>
                    <div class="line"></div>
                    <div>{{ $deposit->recordedBy?->name ?? '' }}</div>
                </td>
            </tr>
        </table>

        <div class="footer">
            Dicetak {{ $printedAt }} · Slip ini adalah bukti setoran yang sah.
        </div>
    </div>
</body>

</html>
