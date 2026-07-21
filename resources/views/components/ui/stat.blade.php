@props([
    'label' => '',
    'value' => '',
    'delta' => null,        // mis. "+12%"
    'deltaUp' => true,
])

{{-- Stat/bento card: nominal oversized (signature anti-template) --}}
<div {{ $attributes->class('rounded-2xl border border-border bg-surface p-6 shadow-sm transition duration-150 ease-out hover:shadow-md') }}>
    <p class="text-sm font-medium text-muted">{{ $label }}</p>
    <div class="mt-2 flex items-baseline gap-2">
        <span class="text-3xl font-bold tracking-tight text-text tabular-nums">{{ $value }}</span>
        @if ($delta)
            <span class="text-xs font-medium {{ $deltaUp ? 'text-success' : 'text-danger' }}">{{ $delta }}</span>
        @endif
    </div>
    @isset($foot)<p class="mt-1 text-xs text-muted">{{ $foot }}</p>@endisset
</div>
