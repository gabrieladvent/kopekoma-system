{{-- Kop laporan: logo (opsional) + nama aplikasi + alamat/kota + telepon. --}}
<table class="kop">
    <tr>
        @if ($kop['logo'])
            <td class="kop-logo-cell"><img src="{{ $kop['logo'] }}" class="kop-logo" alt=""></td>
        @endif
        <td class="kop-text">
            <div class="kop-name">{{ $kop['app_name'] }}</div>
            @if ($kop['address'])
                <div class="kop-line">{{ $kop['address'] }}{{ $kop['city'] ? ', '.$kop['city'] : '' }}</div>
            @elseif ($kop['city'])
                <div class="kop-line">{{ $kop['city'] }}</div>
            @endif
            @if ($kop['phone'])
                <div class="kop-line">Telp. {{ $kop['phone'] }}</div>
            @endif
        </td>
    </tr>
</table>
<div class="kop-rule"></div>
