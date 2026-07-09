{{-- Blok tanda tangan: kota + tanggal cetak, jabatan, ruang ttd, nama. --}}
<table class="ttd">
    <tr>
        <td class="ttd-spacer"></td>
        <td class="ttd-box">
            <div>{{ $kop['city'] ? $kop['city'].', ' : '' }}{{ $generatedAt->format('d/m/Y') }}</div>
            <div>{{ $kop['signatory_position'] ?: 'Pengurus' }}</div>
            <div class="ttd-space"></div>
            <div class="ttd-name">{{ $kop['signatory_name'] ?: '(..............................)' }}</div>
        </td>
    </tr>
</table>
