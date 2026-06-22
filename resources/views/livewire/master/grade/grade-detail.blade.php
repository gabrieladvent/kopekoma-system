<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('master.grades') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar golongan
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="academic-cap" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $grade->code }}</x-ui.badge>
                    <x-ui.badge :color="$grade->is_active ? 'success' : 'neutral'">{{ $grade->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $grade->name }}</h2>
            </div>
        </div>

        {{-- Aksi (hanya saat tidak sedang edit) --}}
        @unless ($editing)
            <div class="flex shrink-0 items-center gap-2">
                @can('update_grade')
                    <x-ui.button variant="ghost" wire:click="startEdit">
                        <x-ui.icon name="pencil" class="h-4 w-4" /> Edit
                    </x-ui.button>
                @endcan
                @can('delete_grade')
                    <x-ui.button variant="danger"
                        x-on:click="$dispatch('confirm-action', {
                            title: 'Hapus golongan {{ $grade->name }}?',
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

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Info / Form --}}
        <div class="lg:col-span-1">
            <x-ui.card>
                @unless ($editing)
                    {{-- READ-ONLY --}}
                    <h3 class="text-sm font-semibold text-text">Informasi Golongan</h3>
                    <dl class="mt-4 space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted">Simpanan Wajib / Bulan</dt>
                            <dd class="mt-1 text-2xl font-bold tabular-nums text-text">Rp {{ number_format((int) $grade->mandatory_savings_amount, 0, ',', '.') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted">Jumlah Anggota</dt>
                            <dd class="mt-1 text-sm font-semibold text-text">{{ number_format($grade->members_count, 0, ',', '.') }} anggota</dd>
                        </div>
                        <div class="grid grid-cols-2 gap-4 border-t border-border pt-4">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Dibuat</dt>
                                <dd class="mt-1 text-sm text-text">{{ $grade->created_at?->translatedFormat('d M Y H:i') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Diperbarui</dt>
                                <dd class="mt-1 text-sm text-text">{{ $grade->updated_at?->translatedFormat('d M Y H:i') }}</dd>
                            </div>
                        </div>
                    </dl>
                @else
                    {{-- EDIT MODE --}}
                    <h3 class="text-sm font-semibold text-text">Edit Golongan</h3>
                    <form wire:submit="save" class="mt-4 space-y-4">
                        {{-- Kode + generate --}}
                        <div class="space-y-1">
                            <label for="code" class="block text-sm font-medium text-text">Kode</label>
                            <div class="flex items-center gap-2">
                                <input id="code" type="text" wire:model="code" placeholder="GOL-0001"
                                       @class([
                                           'h-10 w-full rounded-lg border bg-surface px-3 font-mono text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                           'border-border' => ! $errors->has('code'),
                                           'border-danger focus-visible:ring-danger' => $errors->has('code'),
                                       ])>
                                <button type="button" wire:click="generateCode" title="Generate kode otomatis"
                                        class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-muted transition hover:bg-border/50 hover:text-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                                    <x-ui.icon name="sparkles" class="h-4.5 w-4.5" />
                                </button>
                            </div>
                            @error('code')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        {{-- Nama --}}
                        <x-ui.input label="Nama Golongan" name="name" wire:model="name" placeholder="Golongan I" :error="$errors->first('name')" />

                        {{-- Simpanan wajib --}}
                        <div class="space-y-1"
                             x-data="{
                                raw: @entangle('mandatory_savings_amount'),
                                display: '',
                                fmt(v) { v = String(v ?? '').replace(/\D/g, ''); return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
                                init() {
                                    this.display = this.fmt(this.raw);
                                    this.$watch('raw', (v) => { const f = this.fmt(v); if (f !== this.display) this.display = f; });
                                },
                                onInput(e) {
                                    const digits = e.target.value.replace(/\D/g, '');
                                    this.raw = digits === '' ? null : parseInt(digits, 10);
                                    this.display = this.fmt(digits);
                                },
                             }">
                            <label for="msa" class="block text-sm font-medium text-text">Simpanan Wajib / Bulan</label>
                            <div class="flex items-center rounded-lg border bg-surface transition focus-within:ring-2 focus-within:ring-primary"
                                 @class(['border-border' => ! $errors->has('mandatory_savings_amount'), 'border-danger focus-within:ring-danger' => $errors->has('mandatory_savings_amount')])>
                                <span class="pl-3 text-sm text-muted">Rp</span>
                                <input id="msa" type="text" inputmode="numeric" :value="display" @input="onInput($event)" placeholder="50.000"
                                       class="h-10 w-full rounded-lg bg-transparent px-2 text-sm text-text placeholder:text-muted focus-visible:outline-none">
                            </div>
                            @error('mandatory_savings_amount')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                        </div>

                        {{-- Toggle aktif --}}
                        <label class="flex items-center gap-2 text-sm text-text">
                            <input type="checkbox" wire:model="is_active" class="h-4 w-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                            Aktif
                        </label>

                        <div class="flex justify-end gap-3 border-t border-border pt-4">
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
        <div class="lg:col-span-2">
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
