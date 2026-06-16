<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Anggota {{ $member->member_number }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; padding: 0; }
        .card {
            width: 320px;
            height: 200px;
            border: 1px solid #1f2937;
            border-radius: 10px;
            padding: 14px 18px;
            box-sizing: border-box;
        }
        .header { border-bottom: 2px solid #1f2937; padding-bottom: 6px; margin-bottom: 8px; }
        .title { font-size: 13px; font-weight: bold; color: #111827; }
        .subtitle { font-size: 9px; color: #6b7280; }
        table { width: 100%; font-size: 10px; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        td.label { width: 38%; color: #6b7280; }
        td.sep { width: 4%; }
        .number { font-size: 12px; font-weight: bold; color: #1d4ed8; letter-spacing: 1px; }
        .footer { font-size: 8px; color: #9ca3af; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="title">KPRI KOPEKOMA</div>
            <div class="subtitle">Kartu Tanda Anggota</div>
        </div>
        <div class="number">{{ $member->member_number }}</div>
        <table>
            <tr>
                <td class="label">Nama</td><td class="sep">:</td>
                <td>{{ $member->full_name }}</td>
            </tr>
            <tr>
                <td class="label">NIK</td><td class="sep">:</td>
                <td>{{ $member->nik }}</td>
            </tr>
            <tr>
                <td class="label">OPD</td><td class="sep">:</td>
                <td>{{ $member->agency?->agency_name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Golongan</td><td class="sep">:</td>
                <td>{{ $member->grade?->code ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Bergabung</td><td class="sep">:</td>
                <td>{{ optional($member->join_date)->format('d M Y') ?? '-' }}</td>
            </tr>
        </table>
        <div class="footer">Kartu ini sah sebagai bukti keanggotaan koperasi.</div>
    </div>
</body>
</html>
