<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="shield" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Peran & Izin</h2>
                <p class="mt-0.5 text-sm text-muted">Kelola peran (role) dan hak akses (permission) tiap peran.</p>
            </div>
        </div>

        <x-ui.button :href="route('system.roles.create')" wire:navigate class="shrink-0">
            <x-ui.icon name="plus" class="h-4.5 w-4.5" /> Tambah Peran
        </x-ui.button>
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-5 py-3 text-left">Peran</th>
                        <th class="px-5 py-3 text-left">Guard</th>
                        <th class="px-5 py-3 text-right">Jumlah Izin</th>
                        <th class="px-5 py-3 text-right">Pengguna</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($roles as $role)
                        <tr class="transition hover:bg-bg/60" wire:key="role-{{ $role['id'] }}">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-text">{{ $role['name'] }}</span>
                                    @if ($role['is_super_admin'])
                                        <x-ui.badge color="primary">Super</x-ui.badge>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge color="neutral" class="font-mono">{{ $role['guard_name'] }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-right">
                                @if ($role['is_super_admin'])
                                    <span class="text-xs text-muted">semua izin</span>
                                @else
                                    <span class="font-semibold tabular-nums text-text">{{ $role['permissions_count'] }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right tabular-nums text-text">{{ $role['users_count'] }}</td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="pencil" :href="route('system.roles.edit', $role['id'])" wire:navigate>
                                            {{ $role['is_super_admin'] ? 'Lihat' : 'Edit' }}
                                        </x-ui.dropdown-item>
                                        @unless ($role['is_super_admin'])
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="trash" variant="danger"
                                                x-on:click="$dispatch('confirm-action', {
                                                    title: 'Hapus peran {{ $role['name'] }}?',
                                                    message: 'Pengguna kehilangan peran ini. Tindakan tidak dapat dibatalkan.',
                                                    confirmLabel: 'Hapus', variant: 'danger',
                                                    method: 'delete', params: [{{ $role['id'] }}],
                                                })">Hapus</x-ui.dropdown-item>
                                        @endunless
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
