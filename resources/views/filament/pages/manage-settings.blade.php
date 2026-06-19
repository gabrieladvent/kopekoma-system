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
</x-filament-panels::page>
