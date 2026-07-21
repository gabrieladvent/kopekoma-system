@php($isEdit = filled($roleId))
<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('system.roles') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar peran
    </a>

    {{-- Header --}}
    <div class="flex items-start gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
            <x-ui.icon name="shield" class="h-6 w-6" />
        </span>
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-text">{{ $isEdit ? 'Edit Peran' : 'Tambah Peran' }}</h2>
            <p class="mt-0.5 text-sm text-muted">Tentukan nama peran dan centang izin yang dimiliki.</p>
        </div>
    </div>

    @if ($isSuperAdmin)
        <div class="flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3">
            <x-ui.icon name="shield" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
            <p class="text-sm text-text">Peran <span class="font-semibold">super_admin</span> selalu memiliki seluruh izin secara otomatis (via gate) dan tidak dapat diubah di sini.</p>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- Identitas peran --}}
        <x-ui.card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="name" class="block text-sm font-medium text-text">Nama Peran</label>
                    <input id="name" type="text" wire:model="name" placeholder="mis. bendahara" @disabled($isSuperAdmin)
                           @class([
                               'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:opacity-60',
                               'border-border' => ! $errors->has('name'),
                               'border-danger focus-visible:ring-danger' => $errors->has('name'),
                           ])>
                    @error('name')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1">
                    <label class="block text-sm font-medium text-text">Guard</label>
                    <input type="text" value="{{ $guard_name }}" disabled
                           class="h-10 w-full rounded-lg border border-border bg-bg px-3 font-mono text-sm text-muted">
                </div>
            </div>
        </x-ui.card>

        {{-- Matriks izin --}}
        <x-ui.card>
            <div class="flex flex-col gap-3 border-b border-border pb-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2">
                    <x-ui.icon name="key" class="h-5 w-5 text-primary" />
                    <h3 class="text-sm font-semibold text-text">Hak Akses</h3>
                    <span class="text-xs text-muted">{{ count($selected) }} / {{ $totalPermissions }} izin</span>
                </div>
                @unless ($isSuperAdmin)
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="selectAllPermissions"
                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-text transition hover:bg-border/50">Pilih semua</button>
                        <button type="button" wire:click="clearPermissions"
                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-muted transition hover:bg-border/50">Kosongkan</button>
                    </div>
                @endunless
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach ($groups as $group)
                    @php($checkedInGroup = count(array_intersect($group['names'], $selected)))
                    @php($allChecked = $checkedInGroup === count($group['names']))
                    <div class="rounded-xl border border-border p-4">
                        <div class="flex items-center justify-between gap-2">
                            <label class="flex items-center gap-2.5 text-sm font-semibold text-text {{ $isSuperAdmin ? '' : 'cursor-pointer' }}">
                                <input type="checkbox" @disabled($isSuperAdmin) @checked($allChecked)
                                       wire:click="toggleGroup({{ \Illuminate\Support\Js::from($group['names']) }})"
                                       class="h-4 w-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                                {{ $group['label'] }}
                            </label>
                            <span class="text-[11px] tabular-nums text-muted">{{ $checkedInGroup }}/{{ count($group['names']) }}</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
                            @foreach ($group['perms'] as $perm => $label)
                                <label class="flex items-center gap-2 text-xs text-muted {{ $isSuperAdmin ? '' : 'cursor-pointer hover:text-text' }}">
                                    <input type="checkbox" value="{{ $perm }}" wire:model.live="selected" @disabled($isSuperAdmin)
                                           class="h-3.5 w-3.5 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Izin khusus (non-resource) --}}
            @if (! empty($custom))
                <div class="mt-4 rounded-xl border border-border p-4">
                    <h4 class="flex items-center gap-2 text-sm font-semibold text-text">
                        <x-ui.icon name="sparkles" class="h-4 w-4 text-secondary" /> Izin Khusus
                    </h4>
                    <div class="mt-3 grid grid-cols-1 gap-x-3 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($custom as $perm => $label)
                            <label class="flex items-center gap-2 text-xs text-muted {{ $isSuperAdmin ? '' : 'cursor-pointer hover:text-text' }}">
                                <input type="checkbox" value="{{ $perm }}" wire:model.live="selected" @disabled($isSuperAdmin)
                                       class="h-3.5 w-3.5 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-ui.card>

        {{-- Actions --}}
        <div class="flex items-center justify-end gap-3">
            <x-ui.button variant="ghost" :href="route('system.roles')" wire:navigate>Batal</x-ui.button>
            @unless ($isSuperAdmin)
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Peran' }}
                </x-ui.button>
            @endunless
        </div>
    </form>

    <x-ui.toast-host />
</div>
