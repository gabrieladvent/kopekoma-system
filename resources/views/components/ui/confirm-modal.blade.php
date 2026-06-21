{{--
    Popup konfirmasi reusable (singleton). Pasang SEKALI di dalam tiap komponen
    Livewire (di root, bukan di-teleport — agar $wire tetap valid), lalu picu
    dari tombol mana pun:

      <x-ui.dropdown-item variant="danger" icon="trash"
          x-on:click="$dispatch('confirm-action', {
              title: 'Hapus item?', message: 'Tindakan ini permanen.',
              confirmLabel: 'Hapus', variant: 'danger',
              method: 'deleteItem', params: [itemId],
          })">Hapus</x-ui.dropdown-item>

    `method` + `params` dipanggil di komponen Livewire saat user menekan konfirmasi.
--}}
<div
    x-data="{
        open: false,
        title: 'Konfirmasi',
        message: '',
        confirmLabel: 'Lanjut',
        cancelLabel: 'Batal',
        variant: 'primary',
        method: null,
        params: [],
        start(e) {
            this.title = e.detail.title ?? 'Konfirmasi';
            this.message = e.detail.message ?? '';
            this.confirmLabel = e.detail.confirmLabel ?? 'Lanjut';
            this.cancelLabel = e.detail.cancelLabel ?? 'Batal';
            this.variant = e.detail.variant ?? 'primary';
            this.method = e.detail.method ?? null;
            this.params = e.detail.params ?? [];
            this.open = true;
        },
        run() {
            if (this.method) { $wire[this.method](...this.params); }
            this.open = false;
        },
        get accent() {
            return this.variant === 'danger' ? 'bg-danger/10 text-danger'
                 : this.variant === 'warning' ? 'bg-warning/10 text-warning'
                 : 'bg-primary/10 text-primary';
        },
        get confirmBtn() {
            return this.variant === 'danger' ? 'bg-danger text-white hover:opacity-90'
                 : 'bg-primary text-white hover:bg-primary-hover';
        },
    }"
    @confirm-action.window="start($event)"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[60] flex items-center justify-center p-4"
>
    <div x-show="open"
         x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-black/40" @click="open = false"></div>

    <div x-show="open"
         x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         @keydown.escape.window="open = false"
         role="alertdialog" aria-modal="true"
         class="relative w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full" :class="accent">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            </span>
            <div>
                <h3 class="text-base font-semibold tracking-tight text-text" x-text="title"></h3>
                <p class="mt-1 text-sm text-muted" x-show="message" x-text="message"></p>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <button type="button" @click="open = false"
                    class="inline-flex h-10 items-center justify-center rounded-lg border border-border px-4 text-sm font-medium text-text transition duration-150 ease-out hover:bg-border/50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    x-text="cancelLabel"></button>
            <button type="button" @click="run()"
                    class="inline-flex h-10 items-center justify-center rounded-lg px-4 text-sm font-medium transition duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.98]"
                    :class="confirmBtn" x-text="confirmLabel"></button>
        </div>
    </div>
</div>
