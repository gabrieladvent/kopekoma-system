<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Kuitansi Angsuran {{ $installment->installment_number }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; padding: 24px; color: #111827; }
        .slip { width: 100%; max-width: 540px; border: 1px solid #1f2937; border-radius: 8px; padding: 20px 24px; box-sizing: border-box; }
        .header { border-bottom: 2px solid #1f2937; padding-bottom: 8px; margin-bottom: 14px; text-align: center; }
        .org { font-size: 15px; font-weight: bold; letter-spacing: 1px; }
        .doc-title { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .number { font-size: 13px; font-weight: bold; color: #1d4ed8; letter-spacing: 1px; margin-bottom: 12px; }
        table { width: 100%; font-size: 11px; border-collapse: collapse; }
        td { padding: 4px 0; vertical-align: top; }
        td.label { width: 40%; color: #6b7280; }
        td.sep { width: 4%; }
        .amount-row td { border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; padding: 8px 0; }
        .amount { font-size: 15px; font-weight: bold; color: #047857; }
        .reversal-flag { margin-top: 10px; padding: 6px 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; color: #b91c1c; font-size: 10px; font-weight: bold; }
        .footer { margin-top: 18px; font-size: 9px; color: #9ca3af; border-top: 1px dashed #d1d5db; padding-top: 8px; }
        .sign { margin-top: 24px; font-size: 10px; }
        .sign td { text-align: center; }
        .sign .line { border-top: 1px solid #9ca3af; width: 60%; margin: 36px auto 4px; }
    </style>
</head>

<body>
    <div class="slip">
        <div class="header">
            <div class="org">KPRI KOPEKOMA</div>
            <div class="doc-title">Kuitansi Pembayaran Angsuran</div>
        </div>

        <div class="number">{{ $installment->installment_number }}</div>

        <table>
            <tr><td class="label">Nama Anggota</td><td class="sep">:</td><td>{{ $installment->loan?->member?->full_name ?? '-' }}</td></tr>
            <tr><td class="label">No. Pinjaman</td><td class="sep">:</td><td>{{ $installment->loan?->loan_number ?? '-' }}</td></tr>
            <tr><td class="label">Angsuran Ke</td><td class="sep">:</td><td>{{ $installment->installment_seq }}</td></tr>
            <tr><td class="label">Tanggal Bayar</td><td class="sep">:</td><td>{{ optional($installment->payment_date)->format('d M Y') ?? '-' }}</td></tr>
            <tr><td class="label">Metode</td><td class="sep">:</td><td>{{ $paymentMethodLabel }}</td></tr>
            @php($bd = $installment->breakdown())
            {{-- Istilah mengikuti Bukti Penerimaan Kas resmi: Piutang SP (pokok), Bunga SP (jasa). --}}
            <tr><td class="label">Piutang SP</td><td class="sep">:</td><td>Rp {{ number_format((float) $bd['principal'], 0, ',', '.') }}</td></tr>
            <tr><td class="label">Bunga SP</td><td class="sep">:</td><td>Rp {{ number_format((float) $bd['interest'], 0, ',', '.') }}</td></tr>
            <tr><td class="label">Tabungan Berjangka</td><td class="sep">:</td><td>Rp {{ number_format((float) $bd['time_deposit'], 0, ',', '.') }}</td></tr>
            @if (bccomp($bd['other'], '0', 2) > 0)
                <tr><td class="label">Kelebihan Bayar</td><td class="sep">:</td><td>Rp {{ number_format((float) $bd['other'], 0, ',', '.') }}</td></tr>
            @endif
            <tr class="amount-row"><td class="label">Total Dibayar</td><td class="sep">:</td><td class="amount">Rp {{ number_format((float) $installment->amount_paid, 0, ',', '.') }}</td></tr>
            <tr><td class="label">Sisa Pokok</td><td class="sep">:</td><td>Rp {{ number_format((float) $installment->loan->remainingPrincipal(), 0, ',', '.') }}</td></tr>
        </table>

        @if ($installment->is_reversal)
            <div class="reversal-flag">TRANSAKSI REVERSAL — kuitansi ini mencatat pembatalan pembayaran (koreksi).</div>
        @endif

        <table class="sign">
            <tr>
                <td><div>Anggota</div><div class="line"></div><div>{{ $installment->loan?->member?->full_name ?? '' }}</div></td>
                <td><div>Petugas</div><div class="line"></div><div>{{ $installment->recordedBy?->name ?? '' }}</div></td>
            </tr>
        </table>

        <div class="footer">Dicetak {{ $printedAt }} · Kuitansi ini adalah bukti pembayaran angsuran yang sah.</div>
    </div>
</body>

</html>
