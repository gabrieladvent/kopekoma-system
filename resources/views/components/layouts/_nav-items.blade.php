{{-- Daftar link dalam satu grup nav. Dipakai grup statis (Utama) & collapsible. --}}
@foreach ($visible as $item)
    @php($label = $item[0])
    @php($icon = $item[1])
    @php($route = $item[2])
    @php($active = $route && (request()->routeIs($route) || request()->routeIs($route . '.*')))
    <a href="{{ $route ? route($route) : '#' }}"
        @if ($route) wire:navigate @endif
        @click="sidebarOpen = false" @class([
            'group flex items-center gap-3 rounded-xl px-3 py-2 font-medium transition duration-150 ease-out',
            'bg-primary/10 text-primary' => $active,
            'text-muted hover:bg-border/40 hover:text-text' => !$active,
        ])>
        <span @class([
            'w-0.5 self-stretch rounded-full transition',
            'bg-primary' => $active,
            'bg-transparent' => !$active,
        ])></span>
        <x-ui.icon :name="$icon" class="h-4.5 w-4.5 shrink-0" />
        {{ $label }}
    </a>
@endforeach
