<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Tanda Terima Pinjaman {{ $loan->loan_number }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; padding: 24px; color: #111827; }
        .doc { width: 100%; max-width: 640px; border: 1px solid #1f2937; border-radius: 8px; padding: 20px 24px; box-sizing: border-box; }
        .header { border-bottom: 2px solid #1f2937; padding-bottom: 8px; margin-bottom: 14px; text-align: center; }
        .org { font-size: 15px; font-weight: bold; letter-spacing: 1px; }
        .doc-title { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .number { font-size: 13px; font-weight: bold; color: #1d4ed8; letter-spacing: 1px; margin-bottom: 12px; }
        table { width: 100%; font-size: 11px; border-collapse: collapse; }
        td { padding: 4px 0; vertical-align: top; }
        td.label { width: 36%; color: #6b7280; }
        td.sep { width: 4%; }
        .amount-row td { border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; padding: 8px 0; }
        .amount { font-size: 15px; font-weight: bold; color: #047857; }
        .section-title { font-size: 11px; font-weight: bold; margin: 16px 0 6px; color: #1f2937; }
        .sched { font-size: 10px; }
        .sched th, .sched td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: right; }
        .sched th { background: #f3f4f6; text-align: center; }
        .sched td.c { text-align: center; }
        .footer { margin-top: 18px; font-size: 9px; color: #9ca3af; border-top: 1px dashed #d1d5db; padding-top: 8px; }
        .sign { margin-top: 24px; font-size: 10px; }
        .sign td { text-align: center; }
        .sign .line { border-top: 1px solid #9ca3af; width: 60%; margin: 36px auto 4px; }
    </style>
</head>

<body>
    <div class="doc">
        <div class="header">
            <div class="org">KPRI KOPEKOMA</div>
            <div class="doc-title">Tanda Terima Pinjaman Uang</div>
        </div>

        <div class="number">{{ $loan->loan_number }}</div>

        <table>
            <tr><td class="label">Nama Anggota</td><td class="sep">:</td><td>{{ $loan->member?->full_name ?? '-' }}</td></tr>
            <tr><td class="label">No. Anggota</td><td class="sep">:</td><td>{{ $loan->member?->member_number ?? '-' }}</td></tr>
            <tr><td class="label">Jenis Pinjaman</td><td class="sep">:</td><td>{{ $loanTypeLabel }}</td></tr>
            <tr><td class="label">Jumlah Diajukan</td><td class="sep">:</td><td>Rp {{ number_format((float) $loan->principal_amount, 0, ',', '.') }}</td></tr>
            <tr><td class="label">Jangka Waktu</td><td class="sep">:</td><td>{{ $loan->term_months }} bulan</td></tr>
            <tr><td class="label">Tanggal Pencairan</td><td class="sep">:</td><td>{{ optional($loan->disbursement_date)->format('d M Y') ?? '-' }}</td></tr>
            <tr><td class="label">Biaya Admin{{ $adminRateLabel }}</td><td class="sep">:</td><td>Rp {{ number_format((float) $loan->admin_fee, 0, ',', '.') }}</td></tr>
            <tr><td class="label">SWP{{ $swpRateLabel }}</td><td class="sep">:</td><td>Rp {{ number_format((float) $loan->swp_amount, 0, ',', '.') }}</td></tr>
            <tr class="amount-row"><td class="label">Dana Diterima</td><td class="sep">:</td><td class="amount">Rp {{ number_format((float) $loan->disbursed_amount, 0, ',', '.') }}</td></tr>
        </table>

        @if ($loan->schedules->isNotEmpty())
            <div class="section-title">Jadwal Angsuran</div>
            <table class="sched">
                <thead>
                    <tr>
                        <th>#</th><th>Jatuh Tempo</th><th>Piutang SP</th><th>Bunga SP</th><th>Tab. Berjangka</th><th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loan->schedules->sortBy('installment_seq') as $s)
                        <tr>
                            <td class="c">{{ $s->installment_seq }}</td>
                            <td class="c">{{ optional($s->due_date)->format('d/m/Y') }}</td>
                            <td>{{ number_format((float) $s->principal_due, 0, ',', '.') }}</td>
                            <td>{{ number_format((float) $s->interest_due, 0, ',', '.') }}</td>
                            <td>{{ number_format((float) $s->time_deposit_due, 0, ',', '.') }}</td>
                            <td>{{ number_format((float) $s->total_due, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <table class="sign">
            <tr>
                <td><div>Penerima (Anggota)</div><div class="line"></div><div>{{ $loan->member?->full_name ?? '' }}</div></td>
                <td><div>Petugas</div><div class="line"></div><div>{{ $loan->recordedBy?->name ?? '' }}</div></td>
            </tr>
        </table>

        <div class="footer">Dicetak {{ $printedAt }} · Dokumen ini adalah bukti pencairan pinjaman yang sah.</div>
    </div>
</body>

</html>
