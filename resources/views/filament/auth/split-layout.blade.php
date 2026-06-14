@php
    $livewire ??= null;
    $appName ??= config('app.name');
    $logoUrl ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="fi-simple-layout flex min-h-screen w-full flex-col lg:flex-row">
        {{-- Left: branding panel --}}
        <aside
            class="relative hidden w-full flex-col justify-between overflow-hidden bg-primary-600 p-12 text-white lg:flex lg:w-1/2 dark:bg-primary-700"
        >
            <div
                class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"
            ></div>
            <div
                class="pointer-events-none absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-black/10 blur-3xl"
            ></div>

            <div class="relative flex items-center gap-x-3">
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $appName }}" class="h-10 w-auto" />
                @else
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-white/15 text-lg font-bold"
                    >
                        {{ \Illuminate\Support\Str::substr($appName, 0, 1) }}
                    </span>
                @endif
                <span class="text-lg font-semibold">{{ $appName }}</span>
            </div>

            <div class="relative space-y-4">
                <h1 class="text-3xl font-bold leading-tight">
                    Sistem Informasi Koperasi
                </h1>
                <p class="max-w-md text-white/80">
                    Kelola anggota, simpanan, dan pinjaman {{ $appName }} dalam satu
                    tempat yang aman dan terintegrasi.
                </p>
            </div>

            <p class="relative text-sm text-white/60">
                &copy; {{ now()->year }} {{ $appName }}. Seluruh hak cipta dilindungi.
            </p>
        </aside>

        {{-- Right: login form --}}
        <div
            class="fi-simple-main-ctn flex w-full flex-grow items-center justify-center p-6 lg:w-1/2"
        >
            <main
                class="fi-simple-main w-full max-w-md rounded-xl bg-white px-6 py-12 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:px-12"
            >
                {{-- Mobile brand (panel hidden on small screens) --}}
                <div class="mb-8 flex items-center justify-center gap-x-3 lg:hidden">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $appName }}" class="h-10 w-auto" />
                    @endif
                    <span class="text-lg font-semibold text-gray-950 dark:text-white">
                        {{ $appName }}
                    </span>
                </div>

                {{ $slot }}
            </main>
        </div>
    </div>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $livewire?->getRenderHookScopes()) }}
</x-filament-panels::layout.base>
