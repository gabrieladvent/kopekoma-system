<x-filament-panels::page>
    <form wire:submit="process" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-banknotes">
                Proses Batch
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
