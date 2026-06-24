@props([
    'color' => 'neutral', // neutral | success | warning | danger | primary
])

@php
    $colors = [
        'neutral' => 'bg-border text-muted',
        'success' => 'bg-success/10 text-success',
        'warning' => 'bg-warning/10 text-warning',
        'danger' => 'bg-danger/10 text-danger',
        'primary' => 'bg-primary/10 text-primary',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium', $colors[$color] ?? $colors['neutral']]) }}>
    {{ $slot }}
</span>
