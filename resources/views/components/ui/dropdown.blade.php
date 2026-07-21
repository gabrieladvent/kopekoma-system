@props([
    'align' => 'right',   // right | left
    'width' => 'w-52',
])

{{--
    Menu aksi "titik 3" (kebab). Konvensi: di tabel/list, SEMUA aksi selain
    View masuk ke sini. Lihat memory livewire-component-patterns.

    Menu pakai position:fixed dengan koordinat dihitung dari tombol — supaya
    TIDAK ter-clip oleh `overflow` pada wrapper tabel/card, sekaligus tetap
    berada di DOM komponen Livewire (jadi wire:click di item tetap valid;
    JANGAN di-teleport ke body).
--}}
<div
    x-data="{
        open: false,
        coords: { top: 0, left: 0 },
        toggle() {
            this.open = ! this.open;
            if (this.open) { this.$nextTick(() => this.reposition()); }
        },
        reposition() {
            const btn = this.$refs.trigger.getBoundingClientRect();
            const menu = this.$refs.menu;
            const w = menu.offsetWidth;
            const h = menu.offsetHeight;
            let left = '{{ $align }}' === 'left' ? btn.left : btn.right - w;
            left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
            // Flip ke atas bila ruang di bawah tidak cukup.
            let top = btn.bottom + 6;
            if (top + h > window.innerHeight - 8 && btn.top - h - 6 > 8) {
                top = btn.top - h - 6;
            }
            this.coords = { top, left };
        },
    }"
    @scroll.window="open = false"
    @resize.window="if (open) reposition()"
    class="relative inline-block text-left"
>
    <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu"
            {{ $attributes->merge(['class' => 'grid h-8 w-8 place-items-center rounded-lg text-muted transition duration-150 ease-out hover:bg-border/60 hover:text-text focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none']) }}>
        @isset($trigger){{ $trigger }}@else<x-ui.icon name="dots" class="h-5 w-5" />@endisset
    </button>

    <div x-ref="menu" x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         @click.outside="open = false" @keydown.escape.window="open = false" @click="open = false"
         :style="`top: ${coords.top}px; left: ${coords.left}px;`"
         class="fixed z-50 {{ $width }} overflow-hidden rounded-xl border border-border bg-surface py-1 shadow-lg"
         role="menu">
        {{ $slot }}
    </div>
</div>
