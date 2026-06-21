@props([
    'title' => null,
    'subtitle' => null,
])

<div {{ $attributes->class('rounded-2xl border border-border bg-surface shadow-sm') }}>
    @if ($title || isset($actions))
        <div class="flex items-start justify-between gap-4 border-b border-border px-6 py-4">
            <div>
                @if ($title)<h3 class="text-sm font-semibold tracking-tight text-text">{{ $title }}</h3>@endif
                @if ($subtitle)<p class="mt-0.5 text-xs text-muted">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="shrink-0">{{ $actions }}</div>@endisset
        </div>
    @endif

    <div class="p-6">
        {{ $slot }}
    </div>
</div>
