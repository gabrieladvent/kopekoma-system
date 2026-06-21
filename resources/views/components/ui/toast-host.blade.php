{{--
    Host toast reusable (singleton). Pasang SEKALI per komponen/halaman Livewire.
    Picu dari Livewire: $this->dispatch('toast', type: 'success', message: '...')
    atau dari Alpine: $dispatch('toast', { type: 'danger', message: '...' }).
    Posisi: tengah atas. Lihat memory livewire-component-patterns.
--}}
<div
    x-data="{
        toasts: [],
        push(e) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type: e.detail.type || 'success', message: e.detail.message });
            setTimeout(() => this.remove(id), 4000);
        },
        remove(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
    }"
    @toast.window="push($event)"
    class="pointer-events-none fixed inset-x-0 top-4 z-70 flex flex-col items-center gap-2.5 px-4"
>
    <template x-for="t in toasts" :key="t.id">
        <div
            x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-[400ms]"
            x-transition:enter-start="opacity-0 -translate-y-4 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 -translate-y-3 scale-95"
            @click="remove(t.id)"
            class="pointer-events-auto flex items-center gap-3 rounded-2xl border border-border bg-surface/95 py-3 pl-3 pr-5 shadow-lg backdrop-blur-sm"
            :class="t.type === 'danger' ? 'border-danger/30' : 'border-success/30'"
            role="status"
        >
            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full"
                  :class="t.type === 'danger' ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'">
                <svg x-show="t.type !== 'danger'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                <svg x-show="t.type === 'danger'" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </span>
            <p class="text-sm font-medium text-text" x-text="t.message"></p>
        </div>
    </template>
</div>
