@php
    // Inject warna brand custom + favicon dari Settings (override default).
    // Aman jika settings belum termigrasi: bungkus dalam try/catch.
    try {
        $g = app(\App\Settings\GeneralSettings::class);
        $primary = $g->theme_primary ?? null;
        $secondary = $g->theme_secondary ?? null;

        $favicon = null;
        $faviconType = null;
        if ($g->favicon_path) {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            // Cache-buster: browser meng-cache favicon agresif; pakai mtime
            // agar favicon baru langsung dipakai tanpa hard-refresh.
            $version = $disk->exists($g->favicon_path) ? $disk->lastModified($g->favicon_path) : null;
            $favicon = \Illuminate\Support\Facades\Storage::url($g->favicon_path).($version ? '?v='.$version : '');
            $faviconType = match (strtolower(pathinfo($g->favicon_path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => null,
            };
        }
    } catch (\Throwable $e) {
        $primary = $secondary = $favicon = $faviconType = null;
    }
@endphp

@if ($primary || $secondary)
    <style>:root{@if($primary)--color-primary:{{ $primary }};--color-primary-hover:{{ $primary }};@endif @if($secondary)--color-secondary:{{ $secondary }};--color-secondary-hover:{{ $secondary }};@endif}</style>
@endif

@if ($favicon)
    <link rel="icon" @if($faviconType)type="{{ $faviconType }}"@endif href="{{ $favicon }}">
    <link rel="shortcut icon" @if($faviconType)type="{{ $faviconType }}"@endif href="{{ $favicon }}">
    <link rel="apple-touch-icon" href="{{ $favicon }}">
@endif
