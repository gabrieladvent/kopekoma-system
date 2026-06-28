@props(['title' => 'KOPEKOMA'])

{{-- Guest shell (login/auth) — lihat memory livewire-style-guide. --}}
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — KOPEKOMA</title>

    {{-- Default paksa Light & abaikan preferensi sistem. Hanya hormati pilihan
         eksplisit user (localStorage 'theme' === 'dark'). --}}
    <script>
        (function () {
            if (localStorage.getItem('theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    <x-brand-theme />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-text font-sans antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
