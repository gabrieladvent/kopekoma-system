<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit">
                Simpan
            </x-filament::button>
        </div>
    </form>

    {{ $this->table }}

    {{-- Salin kredensial ke clipboard saat aksi "Copy Kredensial" mengirim event. --}}
    <div
        x-data
        x-on:copy-credential.window="navigator.clipboard && navigator.clipboard.writeText($event.detail.text)"
    ></div>
</x-filament-panels::page>
