@props([
    'variant' => 'primary', // primary | secondary | ghost | danger
    'type' => 'button',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 h-10 text-sm font-medium transition duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-50 disabled:pointer-events-none active:scale-[0.98]';

    $variants = [
        'primary' => 'bg-primary text-white hover:bg-primary-hover',
        'secondary' => 'bg-secondary/10 text-secondary hover:bg-secondary/20',
        'ghost' => 'border border-border text-text hover:bg-border/50',
        'danger' => 'bg-danger text-white hover:opacity-90',
    ];
@endphp

<button type="{{ $type }}" {{ $attributes->class([$base, $variants[$variant] ?? $variants['primary']]) }}>
    {{ $slot }}
</button>
