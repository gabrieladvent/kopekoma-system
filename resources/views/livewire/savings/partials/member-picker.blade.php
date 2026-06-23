{{--
    Picker anggota (searchable) untuk form Simpanan.
    Komponen pemakai harus pakai trait App\Livewire\Concerns\WithMemberPicker.

    Variabel opsional:
    - $errorKey : kunci error bag (default 'member_id')
    - $label    : label field (default 'Anggota')
--}}
@php($errorKey = $errorKey ?? 'member_id')
@php($label = $label ?? 'Anggota')

<div class="space-y-1" x-data="{ open: false }" @click.outside="open = false">
    <label class="block text-sm font-medium text-text">{{ $label }}</label>

    @if ($selectedMemberLabel)
        {{-- Terpilih --}}
        <div @class([
                'flex items-center justify-between gap-3 rounded-lg border bg-surface px-3 py-2 transition',
                'border-border' => ! $errors->has($errorKey),
                'border-danger' => $errors->has($errorKey),
             ])>
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                    <x-ui.icon name="user" class="h-4 w-4" />
                </span>
                <span class="truncate text-sm font-medium text-text">{{ $selectedMemberLabel }}</span>
            </div>
            <button type="button" wire:click="clearMember" title="Ganti anggota"
                    class="grid h-7 w-7 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-danger/10 hover:text-danger focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <x-ui.icon name="x" class="h-4 w-4" />
            </button>
        </div>
    @else
        {{-- Pencarian --}}
        <div class="relative">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.250ms="memberSearch" @focus="open = true"
                   placeholder="Cari nama, no. anggota, NIK…"
                   @class([
                       'h-10 w-full rounded-lg border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                       'border-border' => ! $errors->has($errorKey),
                       'border-danger focus-visible:ring-danger' => $errors->has($errorKey),
                   ])>

            <div x-show="open" x-cloak x-transition.opacity.duration.150ms
                 class="absolute z-30 mt-1.5 max-h-72 w-full overflow-y-auto rounded-xl border border-border bg-surface py-1 shadow-lg">
                @forelse ($this->memberResults() as $m)
                    <button type="button" wire:key="pick-{{ $m->id }}"
                            wire:click="selectMember('{{ $m->id }}')" @click="open = false"
                            class="flex w-full items-center gap-3 px-3 py-2 text-left transition hover:bg-bg/70 focus-visible:bg-bg/70 focus-visible:outline-none">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-secondary/15 text-xs font-semibold text-secondary">
                            {{ \Illuminate\Support\Str::of($m->full_name)->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('') }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-text">{{ $m->full_name }}</span>
                            <span class="flex items-center gap-1.5 text-xs text-muted">
                                <span class="font-mono">{{ $m->member_number }}</span>
                                @if ($m->grade) · <span>Gol. {{ $m->grade->code }}</span> @endif
                                @if ($m->agency) · <span class="truncate">{{ $m->agency->agency_name }}</span> @endif
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="px-3 py-6 text-center text-sm text-muted">
                        {{ $memberSearch !== '' ? 'Tidak ada anggota yang cocok.' : 'Ketik untuk mencari anggota.' }}
                    </p>
                @endforelse
            </div>
        </div>
    @endif

    @error($errorKey)<p class="text-xs text-danger">{{ $message }}</p>@enderror
</div>
