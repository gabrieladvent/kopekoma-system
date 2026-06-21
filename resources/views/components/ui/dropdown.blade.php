@props([
    'align' => 'right',   // right | left
    'width' => 'w-52',
])

@php
    $alignment = $align === 'left' ? 'left-0 origin-top-left' : 'right-0 origin-top-right';
@endphp

{{--
    Menu aksi "titik 3" (kebab). Konvensi: di tabel/list, SEMUA aksi selain
    View masuk ke sini. Lihat memory livewire-component-patterns.
    Pakai: <x-ui.dropdown> ...<x-ui.dropdown-item>... </x-ui.dropdown>
--}}
<div x-data="{ open: false }" class="relative inline-block text-left">
    <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu"
            {{ $attributes->merge(['class' => 'grid h-8 w-8 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/60 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none']) }}>
        @isset($trigger){{ $trigger }}@else<x-ui.icon name="dots" class="h-5 w-5" />@endisset
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         @click.outside="open = false" @keydown.escape.window="open = false" @click="open = false"
         class="absolute z-30 mt-1.5 {{ $alignment }} {{ $width }} overflow-hidden rounded-xl border border-border bg-surface py-1 shadow-lg"
         role="menu">
        {{ $slot }}
    </div>
</div>
