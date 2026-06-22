@props(['title' => 'KOPEKOMA'])

{{--
    App shell Livewire — sidebar + topbar, konsisten & anti-template.
    Lihat memory livewire-style-guide §6 (signature) & livewire-component-patterns.
--}}
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>

    {{-- Cegah flash dark mode: tentukan class sebelum paint --}}
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    {{-- Custom theme dari Settings (override primary/secondary). Default = token CSS. --}}
    <x-brand-theme />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-text font-sans antialiased">
    <div x-data class="flex min-h-screen">
        {{-- Sidebar: surface bersih, bergrup, item aktif = pill + accent kiri (bukan blok penuh) --}}
        <aside class="hidden lg:flex w-64 shrink-0 flex-col border-r border-border bg-surface">
            <div class="flex h-16 items-center px-5">
                <x-app-logo subtitle="Sistem Koperasi" />
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4 text-sm">
                @php($groups = [
                    'Utama' => [['Dashboard', 'home', 'dashboard']],
                    'Keuangan' => [['Anggota', 'users', null], ['Simpanan', 'wallet', null], ['Pinjaman', 'cash', null], ['Laporan', 'chart', null]],
                    'Master' => [['Golongan', 'academic-cap', 'master.grades'], ['OPD / Instansi', 'building-office', 'master.agencies']],
                    'Sistem' => [['Pengaturan', 'cog', 'settings']],
                ])
                @foreach ($groups as $group => $items)
                    <div class="space-y-1">
                        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-muted/70">{{ $group }}</p>
                        @foreach ($items as [$label, $icon, $route])
                            @php($active = $route && request()->routeIs($route))
                            <a href="{{ $route ? route($route) : '#' }}" @if($route) wire:navigate @endif
                               @class([
                                   'group flex items-center gap-3 rounded-xl px-3 py-2 font-medium transition duration-150 ease-out',
                                   'bg-primary/10 text-primary' => $active,
                                   'text-muted hover:bg-border/40 hover:text-text' => ! $active,
                               ])>
                                <span @class(['w-0.5 self-stretch rounded-full transition', 'bg-primary' => $active, 'bg-transparent' => ! $active])></span>
                                <x-ui.icon :name="$icon" class="h-4.5 w-4.5 shrink-0" />
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="border-t border-border p-3">
                @php($u = auth()->user())
                <div class="flex items-center gap-3 rounded-xl px-3 py-2">
                    <div class="grid h-9 w-9 place-items-center rounded-full bg-secondary/15 text-sm font-semibold text-secondary">
                        {{ $u ? \Illuminate\Support\Str::of($u->name)->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('') : 'GA' }}
                    </div>
                    <div class="min-w-0 leading-tight">
                        <p class="truncate text-sm font-medium">{{ $u?->name ?? 'Pengguna' }}</p>
                        <p class="truncate text-xs text-muted">{{ $u?->getRoleNames()->first() ?? 'Anggota' }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                        @csrf
                        <button type="submit"
                                class="grid h-8 w-8 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-danger/10 hover:text-danger focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none"
                                aria-label="Keluar">
                            <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Topbar --}}
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-border bg-surface/70 px-4 backdrop-blur-md sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <h1 class="text-base font-semibold tracking-tight">{{ $title }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative hidden sm:block">
                        <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
                        <input type="search" placeholder="Cari anggota, transaksi…"
                               class="h-9 w-56 rounded-lg border border-border bg-bg pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:w-72 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                    </div>
                    {{-- Theme toggle --}}
                    <button type="button" @click="$store.theme.toggle()"
                            class="grid h-9 w-9 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/50 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                            aria-label="Ganti tema">
                        <svg x-show="!$store.theme.dark" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
                        <svg x-show="$store.theme.dark" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/></svg>
                    </button>
                </div>
            </header>

            <main class="relative flex-1">
                {{-- Tekstur grid halus (signature) --}}
                <div class="bg-grid pointer-events-none absolute inset-x-0 top-0 h-64" aria-hidden="true"></div>
                <div class="relative mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
