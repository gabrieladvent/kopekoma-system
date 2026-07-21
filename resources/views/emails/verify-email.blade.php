<x-mail::message>
# Verifikasi Alamat Email Anda

Halo{{ $name ? ' '.$name : '' }},

Terima kasih telah bergabung dengan **{{ $appName }}**. Untuk mengaktifkan seluruh fitur akun Anda dan memastikan keamanan, silakan verifikasi alamat email ini dengan menekan tombol di bawah.

<x-mail::button :url="$url" color="success">
Verifikasi Email Sekarang
</x-mail::button>

Tautan ini akan kedaluwarsa dalam 60 menit. Jika tombol tidak berfungsi, salin dan tempel URL berikut ke peramban Anda:

<x-mail::panel>
{{ $url }}
</x-mail::panel>

Jika Anda tidak merasa membuat akun di {{ $appName }}, abaikan email ini — tidak ada tindakan lebih lanjut yang diperlukan.

Terima kasih,<br>
**{{ $appName }}**
</x-mail::message>
