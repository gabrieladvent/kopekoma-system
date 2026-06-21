@php
    // Inject warna brand custom dari Settings (override token CSS default).
    // Aman jika settings belum termigrasi: bungkus dalam try/catch.
    try {
        $g = app(\App\Settings\GeneralSettings::class);
        $primary = $g->theme_primary ?? null;
        $secondary = $g->theme_secondary ?? null;
    } catch (\Throwable $e) {
        $primary = $secondary = null;
    }
@endphp

@if ($primary || $secondary)
    <style>:root{@if($primary)--color-primary:{{ $primary }};--color-primary-hover:{{ $primary }};@endif @if($secondary)--color-secondary:{{ $secondary }};--color-secondary-hover:{{ $secondary }};@endif}</style>
@endif
