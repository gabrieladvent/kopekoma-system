@props([
    'subtitle' => null,   // teks kecil di bawah nama (opsional)
    'showName' => true,
])

@php
    // Branding dari Settings (aman saat belum termigrasi).
    try {
        $g = app(\App\Settings\GeneralSettings::class);
        $appName = $g->app_name ?: config('app.name');
        $logo = $g->logo_path ? \Illuminate\Support\Facades\Storage::url($g->logo_path) : null;
    } catch (\Throwable $e) {
        $appName = config('app.name');
        $logo = null;
    }

    $initial = \Illuminate\Support\Str::of($appName)->trim()->substr(0, 1)->upper();
@endphp

<span {{ $attributes->class('flex items-center gap-2.5') }}>
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $appName }}" class="h-9 w-9 shrink-0 rounded-xl border border-border bg-surface object-contain p-0.5">
    @else
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-gradient text-sm font-bold text-white shadow-sm">{{ $initial }}</span>
    @endif

    @if ($showName)
        <span class="flex flex-col leading-none">
            <span class="text-sm font-bold tracking-tight">{{ $appName }}</span>
            @if ($subtitle)<span class="mt-0.5 text-[11px] font-medium text-muted">{{ $subtitle }}</span>@endif
        </span>
    @endif
</span>
