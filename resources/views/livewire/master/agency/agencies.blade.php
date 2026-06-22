<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="building-office" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Master Data OPD / Instansi</h2>
                <p class="mt-0.5 text-sm text-muted">Kelola data OPD / instansi beserta bendahara gaji dan kontak PIC.</p>
            </div>
        </div>

        @can('create_agency')
            <x-ui.button wire:click="create" class="shrink-0">
                <x-ui.icon name="plus" class="h-4.5 w-4.5" />
                Tambah OPD
            </x-ui.button>
        @endcan
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="relative w-full sm:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari kode, nama, atau bendahara…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="flex items-center gap-2">
            <div class="inline-flex items-center gap-1 rounded-xl border border-border bg-surface p-1 text-sm">
                @foreach (['all' => 'Semua', 'active' => 'Aktif', 'inactive' => 'Non-Aktif'] as $value => $label)
                    <button type="button" wire:click="$set('status', '{{ $value }}')"
                            @class([
                                'rounded-lg px-3 py-1.5 font-medium transition duration-150 ease-out',
                                'bg-primary/10 text-primary' => $status === $value,
                                'text-muted hover:text-text' => $status !== $value,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($this->hasActiveFilters())
                <button type="button" wire:click="clearFilters"
                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-danger transition hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none">
                    <x-ui.icon name="x" class="h-4 w-4" /> Bersihkan
                </button>
            @endif
        </div>
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-5 py-3 text-left">Kode</th>
                        <th class="px-5 py-3 text-left">Nama OPD / Instansi</th>
                        <th class="px-5 py-3 text-left">Bendahara Gaji</th>
                        <th class="px-5 py-3 text-right">Anggota</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                {{-- Skeleton saat loading filter/paging --}}
                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 5; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-5 w-16 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-44 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-32 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-4 w-10 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-5 w-16 animate-pulse rounded-full bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-8 w-8 animate-pulse rounded-lg bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($agencies as $agency)
                        <tr class="transition hover:bg-bg/60" wire:key="agency-{{ $agency->id }}">
                            <td class="px-5 py-4">
                                <x-ui.badge color="primary" class="font-mono">{{ $agency->agency_code }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 font-medium text-text">{{ $agency->agency_name }}</td>
                            <td class="px-5 py-4 text-muted">{{ $agency->payroll_treasurer ?: '—' }}</td>
                            <td class="px-5 py-4 text-right font-semibold tabular-nums text-text">
                                {{ number_format($agency->members_count, 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$agency->status === 'Aktif' ? 'success' : 'neutral'">
                                    {{ $agency->status }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="eye" :href="route('master.agencies.show', $agency)" wire:navigate>Lihat Detail</x-ui.dropdown-item>

                                        @can('update_agency')
                                            <x-ui.dropdown-item icon="pencil" wire:click="edit('{{ $agency->id }}')">Edit</x-ui.dropdown-item>
                                            <x-ui.dropdown-item icon="power" wire:click="toggleActive('{{ $agency->id }}')">
                                                {{ $agency->status === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan' }}
                                            </x-ui.dropdown-item>
                                        @endcan

                                        @can('delete_agency')
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="trash" variant="danger"
                                                x-on:click="$dispatch('confirm-action', {
                                                    title: 'Hapus OPD {{ $agency->agency_name }}?',
                                                    message: 'Tindakan ini permanen dan tidak dapat dibatalkan.',
                                                    confirmLabel: 'Hapus', variant: 'danger',
                                                    method: 'delete', params: ['{{ $agency->id }}'],
                                                })">Hapus</x-ui.dropdown-item>
                                        @endcan
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary">
                                        <x-ui.icon name="building-office" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $search !== '' || $status !== 'all' ? 'Tidak ada OPD yang cocok' : 'Belum ada OPD' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $search !== '' || $status !== 'all'
                                            ? 'Coba ubah kata kunci atau filter status.'
                                            : 'Mulai dengan menambahkan OPD / instansi pertama.' }}
                                    </p>
                                    @can('create_agency')
                                        @if ($search === '' && $status === 'all')
                                            <x-ui.button wire:click="create" class="mt-4 h-9 px-3">
                                                <x-ui.icon name="plus" class="h-4 w-4" /> Tambah OPD
                                            </x-ui.button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($agencies->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $agencies->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Modal: Form Create/Edit (inline, tidak di-teleport agar wire:model & $wire valid).
         .live wajib: tutup via Alpine harus langsung sinkron ke server, jika tidak
         state showForm di server tetap true → buka lagi tidak menghasilkan diff → modal tak muncul. --}}
    <div x-data="{ show: @entangle('showForm').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @keydown.escape.window="show = false"
             role="dialog" aria-modal="true"
             class="relative flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
            <div class="px-6 pt-6">
                <h3 class="text-base font-semibold tracking-tight text-text">
                    {{ $editingId ? 'Edit OPD' : 'Tambah OPD' }}
                </h3>
                <p class="mt-1 text-xs text-muted">Lengkapi data OPD / instansi di bawah ini.</p>
            </div>

            <form wire:submit="save" class="flex min-h-0 flex-col">
                <div class="space-y-4 overflow-y-auto px-6 py-5">
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

                    {{-- Nama --}}
                    <x-ui.input label="Nama OPD / Instansi" name="agency_name" wire:model="agency_name" placeholder="Dinas Kesehatan" :error="$errors->first('agency_name')" />

                    {{-- Alamat --}}
                    <div class="space-y-1">
                        <label for="address" class="block text-sm font-medium text-text">Alamat</label>
                        <textarea id="address" wire:model="address" rows="2" placeholder="Jl. ... No. ..."
                                  @class([
                                      'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                                      'border-border' => ! $errors->has('address'),
                                      'border-danger focus-visible:ring-danger' => $errors->has('address'),
                                  ])></textarea>
                        @error('address')<p class="text-xs text-danger">{{ $message }}</p>@enderror
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
                </div>

                <div class="flex justify-end gap-3 border-t border-border px-6 py-4">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                        <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Simpan
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>

    {{-- Singletons --}}
    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
