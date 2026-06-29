<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="users" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Pengguna</h2>
                <p class="mt-0.5 text-sm text-muted">Kelola akun pengguna, status, dan peran (role) mereka.</p>
            </div>
        </div>

        <x-ui.button :href="route('system.users.create')" wire:navigate class="shrink-0">
            <x-ui.icon name="plus" class="h-4.5 w-4.5" /> Tambah Pengguna
        </x-ui.button>
    </div>

    {{-- Search --}}
    <div class="relative max-w-sm">
        <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-muted">
            <x-ui.icon name="search" class="h-4.5 w-4.5" />
        </span>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau email…"
               class="h-10 w-full rounded-lg border border-border bg-surface pl-10 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-5 py-3 text-left">Pengguna</th>
                        <th class="px-5 py-3 text-left">Peran</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="px-5 py-3 text-left">Verifikasi</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($users as $user)
                        @php($isSelf = $user->id === $currentUserId)
                        <tr class="transition hover:bg-bg/60" wire:key="user-{{ $user->id }}">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-text">{{ $user->name }}</span>
                                    @if ($isSelf)
                                        <x-ui.badge color="primary">Anda</x-ui.badge>
                                    @endif
                                </div>
                                <p class="text-xs text-muted">{{ $user->email }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($user->roles as $role)
                                        <x-ui.badge color="neutral">{{ $role->name }}</x-ui.badge>
                                    @empty
                                        <span class="text-xs text-muted">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                @if ($user->is_active)
                                    <x-ui.badge color="success">Aktif</x-ui.badge>
                                @else
                                    <x-ui.badge color="neutral">Nonaktif</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($user->email_verified_at)
                                    <span class="inline-flex items-center gap-1 text-xs text-success">
                                        <x-ui.icon name="check" class="h-4 w-4" /> Terverifikasi
                                    </span>
                                @else
                                    <span class="text-xs text-muted">Belum</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="pencil" :href="route('system.users.edit', $user->id)" wire:navigate>
                                            Edit
                                        </x-ui.dropdown-item>
                                        @unless ($isSelf)
                                            <x-ui.dropdown-item icon="power" wire:click="toggleActive({{ $user->id }})">
                                                {{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                            </x-ui.dropdown-item>
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="trash" variant="danger"
                                                x-on:click="$dispatch('confirm-action', {
                                                    title: 'Hapus pengguna {{ $user->name }}?',
                                                    message: 'Akun ini akan dihapus permanen. Tindakan tidak dapat dibatalkan.',
                                                    confirmLabel: 'Hapus', variant: 'danger',
                                                    method: 'delete', params: [{{ $user->id }}],
                                                })">Hapus</x-ui.dropdown-item>
                                        @endunless
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-muted">
                                Tidak ada pengguna yang cocok.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($users->hasPages())
        <div>{{ $users->links() }}</div>
    @endif

    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
