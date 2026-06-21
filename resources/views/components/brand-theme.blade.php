@php
    // Inject warna brand custom + favicon dari Settings (override default).
    // Aman jika settings belum termigrasi: bungkus dalam try/catch.
    try {
        $g = app(\App\Settings\GeneralSettings::class);
        $primary = $g->theme_primary ?? null;
        $secondary = $g->theme_secondary ?? null;
        $favicon = $g->favicon_path ? \Illuminate\Support\Facades\Storage::url($g->favicon_path) : null;
    } catch (\Throwable $e) {
        $primary = $secondary = $favicon = null;
    }
@endphp

@if ($primary || $secondary)
    <style>:root{@if($primary)--color-primary:{{ $primary }};--color-primary-hover:{{ $primary }};@endif @if($secondary)--color-secondary:{{ $secondary }};--color-secondary-hover:{{ $secondary }};@endif}</style>
@endif

@if ($favicon)
    <link rel="icon" href="{{ $favicon }}">
@endif
