@props(['title' => 'KOPEKOMA'])

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>

    <script>
        (function() {
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
    <div x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false" class="flex min-h-screen">
        {{-- Data nav + breadcrumb dihitung sekali di sini. $groups jadi satu sumber
             kebenaran untuk sidebar & breadcrumb. --}}
        @php
            $navUser = auth()->user();
            $isSuper = $navUser?->hasRole('super_admin') ?? false;
            $canAudit = $navUser?->hasAnyRole(['super_admin', 'pengurus']) ?? false;
            $canHoliday = $navUser?->can('view_any_member::holiday::saving') ?? false;
            $canShopping = $navUser?->can('view_any_shopping::transaction') ?? false;
            $canBalance = $navUser?->can('view_any_member::savings::balance') ?? false;
            $canDeposit = $navUser?->can('view_any_savings::deposit') ?? false;
            $canWithdrawal = $navUser?->can('view_any_savings::withdrawal') ?? false;
            $canLoan = $navUser?->can('view_any_loan') ?? false;
            $canInstallment = $navUser?->can('view_any_installment') ?? false;
            $canBlacklist = $navUser?->can('view_any_loan::blacklist') ?? false;
            $canLaporanSetoran = $navUser?->can('access_laporan_setoran') ?? false;
            $canLaporanAngsuran = $navUser?->can('access_laporan_angsuran') ?? false;

            $groups = [
                'Utama' => [['Dashboard', 'home', 'dashboard'], ['Setor Simpanan', 'banknotes', 'savings.deposits', $canDeposit], ['Pinjaman', 'receipt-percent', 'loans.index', $canLoan], ['Saldo Anggota', 'wallet-stack', 'savings.balances', $canBalance]],
                'Laporan' => [['Laporan Simpanan', 'chart', 'reports.setoran', $canLaporanSetoran], ['Laporan Angsuran', 'receipt', 'reports.angsuran', $canLaporanAngsuran]],
                'Simpanan' => [['Pencairan Simpanan', 'arrow-up-tray', 'savings.withdrawals', $canWithdrawal], ['Pendaftaran Hari Raya', 'gift', 'savings.holiday', $canHoliday], ['Belanja Toko', 'shopping-cart', 'savings.shopping', $canShopping]],
                'Pinjaman' => [['Angsuran', 'credit-card', 'installments.index', $canInstallment], ['Blacklist Pinjaman', 'no-symbol', 'loans.blacklist', $canBlacklist]],
                'Master' => [['Nasabah Koperasi', 'users', 'master.members'], ['Golongan', 'academic-cap', 'master.grades'], ['OPD / Instansi', 'building-office', 'master.agencies']],
                'Sistem' => [['Log Aktivitas', 'bolt', 'system.activity-logs', $canAudit], ['Pengguna', 'users', 'system.users', $isSuper], ['Peran & Izin', 'shield', 'system.roles', $isSuper], ['Pengaturan', 'cog', 'settings', $navUser?->can('manage_settings') ?? false]],
            ];

            // Breadcrumb diturunkan dari $groups (route → grup + label), tanpa deklarasi per-halaman.
            $bcRouteMap = [];
            foreach ($groups as $bcGroup => $bcItems) {
                foreach ($bcItems as $bcItem) {
                    if (! empty($bcItem[2])) {
                        $bcRouteMap[$bcItem[2]] = ['group' => $bcGroup, 'label' => $bcItem[0]];
                    }
                }
            }

            $bcMatched = null;
            foreach ($bcRouteMap as $bcRn => $bcMeta) {
                if (request()->routeIs($bcRn) || request()->routeIs($bcRn . '.*')) {
                    $bcMatched = ['route' => $bcRn] + $bcMeta;
                    break;
                }
            }

            $crumbs = [];
            if ($bcMatched) {
                $bcOnBase = request()->routeIs($bcMatched['route']);
                if ($bcMatched['route'] !== 'dashboard') {
                    $crumbs[] = ['label' => $bcMatched['group'], 'url' => null];
                }
                $crumbs[] = ['label' => $bcMatched['label'], 'url' => $bcOnBase ? null : route($bcMatched['route'])];
                if (! $bcOnBase) {
                    $crumbs[] = ['label' => $title, 'url' => null];
                }
            } else {
                $crumbs[] = ['label' => $title, 'url' => null];
            }
        @endphp

        {{-- Overlay (mobile only) saat drawer terbuka --}}
        <div x-show="sidebarOpen" x-cloak x-transition:enter="transition-opacity ease-out duration-200"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" @click="sidebarOpen = false"
            class="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm lg:hidden" aria-hidden="true"></div>

        {{-- Sidebar: drawer off-canvas di mobile, statis di desktop.
             Item aktif = pill + accent kiri (bukan blok penuh). --}}
        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 flex-col border-r border-border bg-surface transition-transform duration-200 ease-out lg:sticky lg:top-0 lg:h-screen lg:z-auto lg:translate-x-0">
            <div class="flex h-16 items-center justify-between px-5">
                <x-app-logo subtitle="Sistem Koperasi" />
                <button type="button" @click="sidebarOpen = false" aria-label="Tutup menu"
                    class="grid h-9 w-9 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text lg:hidden">
                    <x-ui.icon name="x" class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4 text-sm">
                @foreach ($groups as $group => $items)
                    @php($visible = array_filter($items, fn($i) => $i[3] ?? true))
                    @if (!empty($visible))
                        @if ($group === 'Utama')
                            {{-- Grup Utama: selalu terbuka, tanpa toggle. --}}
                            <div class="space-y-1">
                                <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-muted/70">{{ $group }}</p>
                                @include('components.layouts._nav-items', ['visible' => $visible])
                            </div>
                        @else
                            {{-- Grup lain: collapsible. Default BUKA, kecuali "Sistem" default TUTUP.
                                 Status kebuka/ketutup persist ke localStorage (override default). --}}
                            @php($slug = \Illuminate\Support\Str::slug($group))
                            @php($groupActive = collect($visible)->contains(fn($i) => ($i[2] ?? null) && (request()->routeIs($i[2]) || request()->routeIs($i[2] . '.*'))))
                            @php($defaultClosed = $group === 'Sistem')
                            @php($storeKey = "localStorage.getItem('nav:{$slug}')")
                            @php($openExpr = $groupActive ? 'true' : ($defaultClosed ? "({$storeKey} === '1')" : "({$storeKey} !== '0')"))
                            <div x-data="{ open: {{ $openExpr }}, toggle() { this.open = !this.open;
                                    localStorage.setItem('nav:{{ $slug }}', this.open ? '1' : '0'); } }" class="space-y-1">
                                <button type="button" @click="toggle()"
                                    class="flex w-full items-center justify-between rounded-lg px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-muted/70 transition hover:text-muted focus-visible:outline-none">
                                    <span>{{ $group }}</span>
                                    <x-ui.icon name="chevron-right"
                                        class="h-3.5 w-3.5 shrink-0 transition-transform duration-200" ::class="open && 'rotate-90'" />
                                </button>
                                <div x-show="open" x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0" class="space-y-1">
                                    @include('components.layouts._nav-items', ['visible' => $visible])
                                </div>
                            </div>
                        @endif
                    @endif
                @endforeach
            </nav>

            <div class="border-t border-border p-3">
                @php($u = auth()->user())
                <div class="flex items-center gap-3 rounded-xl px-3 py-2">
                    <a href="{{ route('profile.edit') }}"
                        class="flex min-w-0 flex-1 items-center gap-3 rounded-lg transition duration-150 ease-out hover:opacity-80"
                        aria-label="Profil saya">
                        @if ($u?->avatarUrl())
                            <img src="{{ $u->avatarUrl() }}" alt="{{ $u->name }}"
                                class="h-9 w-9 shrink-0 rounded-full object-cover">
                        @else
                            <div
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-secondary/15 text-sm font-semibold text-secondary">
                                {{ $u ? \Illuminate\Support\Str::of($u->name)->explode(' ')->take(2)->map(fn($p) => mb_substr($p, 0, 1))->implode('') : 'GA' }}
                            </div>
                        @endif
                        <div class="min-w-0 leading-tight">
                            <p class="truncate text-sm font-medium">{{ $u?->name ?? 'Pengguna' }}</p>
                            <p class="truncate text-xs text-muted">{{ $u?->getRoleNames()->first() ?? 'Anggota' }}</p>
                        </div>
                    </a>
                    <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                        @csrf
                        <button type="submit"
                            class="grid h-8 w-8 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-danger/10 hover:text-danger focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none"
                            aria-label="Keluar">
                            <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.6"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Topbar --}}
            <header
                class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-border bg-surface/70 px-4 backdrop-blur-md sm:px-6 lg:px-8">
                <div class="flex items-center gap-2 sm:gap-3">
                    <button type="button" @click="sidebarOpen = true" aria-label="Buka menu"
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/50 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none lg:hidden">
                        <svg class="h-5.5 w-5.5" fill="none" stroke="currentColor" stroke-width="1.6"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <nav aria-label="Breadcrumb" class="flex min-w-0 items-center gap-1.5 text-sm">
                        <a href="{{ route('dashboard') }}" wire:navigate aria-label="Beranda"
                            class="grid h-7 w-7 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text">
                            <x-ui.icon name="home" class="h-4 w-4" />
                        </a>
                        @foreach ($crumbs as $i => $c)
                            @php($isLast = $i === array_key_last($crumbs))
                            <span @class(['inline-flex items-center gap-1.5', 'hidden sm:inline-flex' => !$isLast])>
                                <x-ui.icon name="chevron-right" class="h-3.5 w-3.5 shrink-0 text-muted/50" />
                                @if ($c['url'] && !$isLast)
                                    <a href="{{ $c['url'] }}" wire:navigate
                                        class="shrink-0 text-muted transition hover:text-text">{{ $c['label'] }}</a>
                                @else
                                    <span @class([
                                        'min-w-0 truncate',
                                        'font-semibold tracking-tight text-text' => $isLast,
                                        'text-muted' => !$isLast,
                                    ])>{{ $c['label'] }}</span>
                                @endif
                            </span>
                        @endforeach
                    </nav>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative hidden sm:block">
                        <x-ui.icon name="search"
                            class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
                        <input type="search" placeholder="Cari anggota, transaksi…"
                            class="h-9 w-56 rounded-lg border border-border bg-bg pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:w-72 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                    </div>
                    {{-- Bell notifikasi --}}
                    @auth
                        @livewire('notification-bell')
                    @endauth
                    {{-- Theme toggle --}}
                    <button type="button" @click="$store.theme.toggle()"
                        class="grid h-9 w-9 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/50 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                        aria-label="Ganti tema">
                        <svg x-show="!$store.theme.dark" class="h-5 w-5" fill="none" stroke="currentColor"
                            stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                        </svg>
                        <svg x-show="$store.theme.dark" x-cloak class="h-5 w-5" fill="none" stroke="currentColor"
                            stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                        </svg>
                    </button>
                </div>
            </header>

            <main class="relative flex-1">
                {{-- Tekstur grid halus (signature) --}}
                <div class="bg-grid pointer-events-none absolute inset-x-0 top-0 h-64" aria-hidden="true"></div>
                <div class="relative mx-auto max-w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    @livewireScripts
</body>

</html>
