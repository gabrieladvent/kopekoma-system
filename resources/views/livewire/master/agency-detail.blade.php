<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('master.agencies') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar OPD
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="building-office" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $agency->agency_code }}</x-ui.badge>
                    <x-ui.badge :color="$agency->status === 'Aktif' ? 'success' : 'neutral'">{{ $agency->status }}</x-ui.badge>
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $agency->agency_name }}</h2>
            </div>
        </div>

        {{-- Aksi (hanya saat tidak sedang edit) --}}
        @unless ($editing)
            <div class="flex shrink-0 items-center gap-2">
                @can('update_agency')
                    <x-ui.button variant="ghost" wire:click="startEdit">
                        <x-ui.icon name="pencil" class="h-4 w-4" /> Edit
                    </x-ui.button>
                @endcan
                @can('delete_agency')
                    <x-ui.button variant="danger"
                        x-on:click="$dispatch('confirm-action', {
                            title: 'Hapus OPD {{ $agency->agency_name }}?',
                            message: 'Tindakan ini permanen dan tidak dapat dibatalkan.',
                            confirmLabel: 'Hapus', variant: 'danger',
                            method: 'delete', params: [],
                        })">
                        <x-ui.icon name="trash" class="h-4 w-4" /> Hapus
                    </x-ui.button>
                @endcan
            </div>
        @endunless
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Info / Form --}}
        <div>
            <x-ui.card>
                @unless ($editing)
                    {{-- READ-ONLY --}}
                    <h3 class="text-sm font-semibold text-text">Informasi OPD</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-5">
                        {{-- Jumlah anggota (highlight) --}}
                        <div class="col-span-2 rounded-xl bg-bg px-4 py-3">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="users" class="h-3.5 w-3.5" /> Jumlah Anggota
                            </dt>
                            <dd class="mt-1 text-2xl font-bold tabular-nums text-text">{{ number_format($agency->members_count, 0, ',', '.') }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="user" class="h-3.5 w-3.5" /> Bendahara Gaji
                            </dt>
                            <dd class="mt-1 text-sm font-medium text-text">{{ $agency->payroll_treasurer ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="phone" class="h-3.5 w-3.5" /> No. HP PIC
                            </dt>
                            <dd class="mt-1 text-sm text-text">{{ $agency->pic_phone_number ?: '—' }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="map-pin" class="h-3.5 w-3.5" /> Alamat
                            </dt>
                            <dd class="mt-1 text-sm text-text">{{ $agency->address ?: '—' }}</dd>
                        </div>
                        <div class="col-span-2 grid grid-cols-2 gap-4 border-t border-border pt-4">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Dibuat</dt>
                                <dd class="mt-1 text-sm text-text">{{ $agency->created_at?->translatedFormat('d M Y H:i') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Diperbarui</dt>
                                <dd class="mt-1 text-sm text-text">{{ $agency->updated_at?->translatedFormat('d M Y H:i') }}</dd>
                            </div>
                        </div>
                    </dl>
                @else
                    {{-- EDIT MODE --}}
                    <h3 class="text-sm font-semibold text-text">Edit OPD</h3>
                    <form wire:submit="save" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- Kode + generate --}}
                        <div class="space-y-1">
                            <label for="agency_code" class="block text-sm font-medium text-text">Kode OPD</label>
                            <div class="flex items-center gap-2">
                                <input id="agency_code" type="text" wire:model="agency_code" placeholder="OPD0001"
                                       @class([
                                           'h-10 w-full rounded-lg border bg-surface px-3 font-mono text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                           'border-border' => ! $errors->has('agency_code'),
                                           'border-danger focus-visible:ring-danger' => $errors->has('agency_code'),
                                       ])>
                                <button type="button" wire:click="generateCode" title="Generate kode otomatis"
                                        class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-muted transition hover:bg-border/50 hover:text-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                                    <x-ui.icon name="sparkles" class="h-4.5 w-4.5" />
                                </button>
                            </div>
                            @error('agency_code')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        {{-- Status --}}
                        <div class="space-y-1">
                            <label for="statusForm" class="block text-sm font-medium text-text">Status</label>
                            <select id="statusForm" wire:model="statusForm"
                                    @class([
                                        'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                        'border-border' => ! $errors->has('statusForm'),
                                        'border-danger focus-visible:ring-danger' => $errors->has('statusForm'),
                                    ])>
                                <option value="Aktif">Aktif</option>
                                <option value="Non-Aktif">Non-Aktif</option>
                            </select>
                            @error('statusForm')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        {{-- Nama --}}
                        <div class="sm:col-span-2">
                            <x-ui.input label="Nama OPD / Instansi" name="agency_name" wire:model="agency_name" placeholder="Dinas Kesehatan" :error="$errors->first('agency_name')" />
                        </div>

                        {{-- Bendahara Gaji --}}
                        <div class="space-y-1">
                            <label for="payroll_treasurer" class="block text-sm font-medium text-text">Bendahara Gaji (PIC)</label>
                            <select id="payroll_treasurer" wire:model="payroll_treasurer"
                                    @class([
                                        'h-10 w-full rounded-lg border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                        'border-border' => ! $errors->has('payroll_treasurer'),
                                        'border-danger focus-visible:ring-danger' => $errors->has('payroll_treasurer'),
                                    ])>
                                <option value="">— Pilih bendahara —</option>
                                @foreach ($treasurers as $name)
                                    <option value="{{ $name }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('payroll_treasurer')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        {{-- No HP PIC (+62) --}}
                        <div class="space-y-1">
                            <label for="pic_phone_number" class="block text-sm font-medium text-text">No. HP PIC</label>
                            <div class="flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary"
                                 @class(['border-border' => ! $errors->has('pic_phone_number'), 'border-danger focus-within:ring-danger' => $errors->has('pic_phone_number')])>
                                <span class="pl-3 text-sm text-muted">+62</span>
                                <input id="pic_phone_number" type="tel" inputmode="numeric" wire:model="pic_phone_number" placeholder="81234567890"
                                       class="h-10 w-full rounded-lg bg-transparent px-2 text-sm text-text placeholder:text-muted focus-visible:outline-none">
                            </div>
                            @error('pic_phone_number')
                                <p class="text-xs text-danger">{{ $message }}</p>
                            @else
                                <p class="text-xs text-muted">Tanpa angka 0 di depan. Disimpan dengan awalan +62.</p>
                            @enderror
                        </div>

                        {{-- Alamat --}}
                        <div class="space-y-1 sm:col-span-2">
                            <label for="address" class="block text-sm font-medium text-text">Alamat</label>
                            <textarea id="address" wire:model="address" rows="2" placeholder="Jl. ... No. ..."
                                      @class([
                                          'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                          'border-border' => ! $errors->has('address'),
                                          'border-danger focus-visible:ring-danger' => $errors->has('address'),
                                      ])></textarea>
                            @error('address')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        <div class="flex justify-end gap-3 border-t border-border pt-4 sm:col-span-2">
                            <x-ui.button type="button" variant="ghost" wire:click="cancelEdit">Batal</x-ui.button>
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                                <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                Simpan
                            </x-ui.button>
                        </div>
                    </form>
                @endunless
            </x-ui.card>
        </div>

        {{-- Audit Trail --}}
        <div>
            <x-ui.card>
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-text">Audit Trail</h3>
                    <span class="text-xs text-muted">Klik baris untuk lihat detail perubahan</span>
                </div>
                <div class="mt-4">
                    @include('livewire.master.partials.audit-trail')
                </div>
            </x-ui.card>
        </div>
    </div>

    {{-- Singletons --}}
    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
