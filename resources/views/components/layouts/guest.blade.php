@props(['title' => 'KOPEKOMA'])

{{-- Guest shell (login/auth) — lihat memory livewire-style-guide. --}}
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — KOPEKOMA</title>

    {{-- Cegah flash dark mode --}}
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @isset($brandPrimary)
        <style>:root{--color-primary:{{ $brandPrimary }};@isset($brandSecondary)--color-secondary:{{ $brandSecondary }};@endisset}</style>
    @endisset

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
