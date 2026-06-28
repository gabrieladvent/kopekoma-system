@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'error' => null,
    'hint' => null,
])

<div class="space-y-1">
    @if ($label)
        <label @if($name) for="{{ $name }}" @endif class="block text-sm font-medium text-text">{{ $label }}</label>
    @endif

    <input
        type="{{ $type }}"
        @if($name) name="{{ $name }}" id="{{ $name }}" @endif
        {{ $attributes->class([
            'w-full rounded-lg border bg-surface px-3 h-10 text-sm text-text placeholder:text-muted transition duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
            'border-border' => ! $error,
            'border-danger focus-visible:ring-danger' => $error,
        ]) }}
    >

    @if ($error)
        <p class="text-xs text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-muted">{{ $hint }}</p>
    @endif
</div>
