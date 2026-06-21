@props([
    'variant' => 'default', // default | danger | warning
    'icon' => null,
])

@php
    $colors = [
        'default' => 'text-text hover:bg-border/50',
        'danger' => 'text-danger hover:bg-danger/10',
        'warning' => 'text-warning hover:bg-warning/10',
    ];
@endphp

<button type="button" role="menuitem"
        {{ $attributes->class(['flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm font-medium transition duration-150 ease-out', $colors[$variant] ?? $colors['default']]) }}>
    @if ($icon)<x-ui.icon :name="$icon" class="h-4 w-4 shrink-0" />@endif
    <span>{{ $slot }}</span>
</button>
