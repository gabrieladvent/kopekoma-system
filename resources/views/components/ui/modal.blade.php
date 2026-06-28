@props([
    'title' => null,
])

{{-- Modal Alpine: kontrol via x-data lokal, buka dengan $dispatch('open-modal') atau set $refs.
     Contoh pakai: bungkus trigger + modal dalam satu x-data="{ open: false }". --}}
<template x-teleport="body">
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        {{-- Overlay --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="absolute inset-0 bg-black/40" @click="open = false"></div>

        {{-- Panel --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @keydown.escape.window="open = false"
             role="dialog" aria-modal="true"
             class="relative w-full max-w-lg rounded-xl border border-border bg-surface p-6 shadow-xl">
            @if ($title)
                <h3 class="text-base font-semibold tracking-tight text-text">{{ $title }}</h3>
            @endif
            <div class="{{ $title ? 'mt-4' : '' }}">
                {{ $slot }}
            </div>
        </div>
    </div>
</template>
