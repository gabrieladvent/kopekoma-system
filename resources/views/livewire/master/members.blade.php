@php($canImport = $this->canManageImportExport())
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="users" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Master Data Anggota</h2>
                <p class="mt-0.5 text-sm text-muted">Kelola data anggota koperasi, dokumen, dan kartu anggota.</p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($canImport)
                <x-ui.button variant="ghost" wire:click="downloadTemplate">
                    <x-ui.icon name="arrow-down-tray" class="h-4.5 w-4.5" />
                    <span class="hidden sm:inline">Template</span>
                </x-ui.button>
                <x-ui.button variant="ghost" wire:click="$set('showImport', true)">
                    <x-ui.icon name="arrow-up-tray" class="h-4.5 w-4.5" />
                    <span class="hidden sm:inline">Import</span>
                </x-ui.button>
            @endif
            @can('create_member')
                <x-ui.button :href="route('master.members.create')" wire:navigate>
                    <x-ui.icon name="plus" class="h-4.5 w-4.5" />
                    Tambah Anggota
                </x-ui.button>
            @endcan
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative w-full lg:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama, no. anggota, NIK…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:flex lg:items-center">
            <select wire:model.live="status"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="all">Semua Status</option>
                <option value="Aktif">Aktif</option>
                <option value="Non-Aktif">Non-Aktif</option>
                <option value="Keluar">Keluar</option>
                <option value="Meninggal">Meninggal</option>
            </select>
            <select wire:model.live="agency"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua OPD</option>
                @foreach ($agencyOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <select wire:model.live="grade"
                    class="h-10 rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                <option value="">Semua Golongan</option>
                @foreach ($gradeOptions as $g)
                    <option value="{{ $g->id }}">{{ $g->code }} — {{ $g->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-5 py-3 text-left">No. Anggota</th>
                        <th class="px-5 py-3 text-left">Nama</th>
                        <th class="px-5 py-3 text-left">NIK</th>
                        <th class="px-5 py-3 text-left">OPD / Instansi</th>
                        <th class="px-5 py-3 text-left">Gol.</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                {{-- Skeleton saat loading filter/paging --}}
                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 5; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-5 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-40 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-32 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-36 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-5 w-12 animate-pulse rounded-full bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-5 w-16 animate-pulse rounded-full bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-8 w-8 animate-pulse rounded-lg bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($members as $member)
                        @php($statusColor = match ($member->status) {
                            'Aktif' => 'success',
                            'Keluar' => 'warning',
                            'Meninggal' => 'danger',
                            default => 'neutral',
                        })
                        <tr class="transition hover:bg-bg/60" wire:key="member-{{ $member->id }}">
                            <td class="px-5 py-4">
                                <x-ui.badge color="primary" class="font-mono">{{ $member->member_number }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4">
                                <a href="{{ route('master.members.show', $member) }}" wire:navigate class="font-medium text-text hover:text-primary">{{ $member->full_name }}</a>
                                <p class="text-xs text-muted">{{ $member->position ?: $member->employment_status }}</p>
                            </td>
                            <td class="px-5 py-4 font-mono text-xs text-muted">{{ $member->nik }}</td>
                            <td class="px-5 py-4 text-text">{{ $member->agency?->agency_name ?? '—' }}</td>
                            <td class="px-5 py-4">
                                <x-ui.badge color="neutral" class="font-mono">{{ $member->grade?->code ?? '—' }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$statusColor">{{ $member->status }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="eye" :href="route('master.members.show', $member)" wire:navigate>Lihat Detail</x-ui.dropdown-item>

                                        @can('update_member')
                                            <x-ui.dropdown-item icon="pencil" :href="route('master.members.edit', $member)" wire:navigate>Edit</x-ui.dropdown-item>
                                        @endcan

                                        @if ($canImport)
                                            <x-ui.dropdown-item icon="printer" :href="route('master.members.card', $member)">Cetak Kartu</x-ui.dropdown-item>
                                        @endif

                                        @can('delete_member')
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="trash" variant="danger"
                                                x-on:click="$dispatch('confirm-action', {
                                                    title: 'Hapus anggota {{ $member->full_name }}?',
                                                    message: 'Data dipindah ke arsip (soft delete) dan tidak tampil di daftar.',
                                                    confirmLabel: 'Hapus', variant: 'danger',
                                                    method: 'delete', params: ['{{ $member->id }}'],
                                                })">Hapus</x-ui.dropdown-item>
                                        @endcan
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary">
                                        <x-ui.icon name="users" class="h-7 w-7" />
                                    </div>
                                    @php($filtering = $search !== '' || $status !== 'all' || $agency !== '' || $grade !== '')
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $filtering ? 'Tidak ada anggota yang cocok' : 'Belum ada anggota' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $filtering
                                            ? 'Coba ubah kata kunci atau filter.'
                                            : 'Mulai dengan menambahkan anggota pertama, atau import dari Excel.' }}
                                    </p>
                                    @can('create_member')
                                        @unless ($filtering)
                                            <x-ui.button :href="route('master.members.create')" wire:navigate class="mt-4 h-9 px-3">
                                                <x-ui.icon name="plus" class="h-4 w-4" /> Tambah Anggota
                                            </x-ui.button>
                                        @endunless
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($members->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $members->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Modal: Import Excel --}}
    @if ($canImport)
        <div x-data="{ show: @entangle('showImport').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
            <div x-show="show"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 @keydown.escape.window="show = false"
                 role="dialog" aria-modal="true"
                 class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
                <h3 class="text-base font-semibold tracking-tight text-text">Import Anggota dari Excel</h3>
                <p class="mt-1 text-xs text-muted">Gunakan format dari tombol <span class="font-medium text-text">Template</span>. Baris tidak valid akan dilewati.</p>

                <form wire:submit="import" class="mt-5 space-y-4">
                    <div class="space-y-1">
                        <label for="importFile" class="block text-sm font-medium text-text">Berkas (.xlsx, .xls, .csv)</label>
                        <input id="importFile" type="file" wire:model="importFile" accept=".xlsx,.xls,.csv"
                               class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/20">
                        <div wire:loading wire:target="importFile" class="text-xs text-muted">Mengunggah berkas…</div>
                        @error('importFile')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="import,importFile">
                            <svg wire:loading wire:target="import" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            Proses Import
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Singletons --}}
    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
