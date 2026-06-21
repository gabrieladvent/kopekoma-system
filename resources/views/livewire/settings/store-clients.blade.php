<div>
    <x-ui.card title="Klien Toko (API Integrasi)" subtitle="Kredensial aplikasi toko untuk mengakses API pemakaian saldo Wajib Belanja.">
        <x-slot:actions>
            <x-ui.button class="h-9 px-3" wire:click="$set('showCreate', true)">Tambah Klien</x-ui.button>
        </x-slot:actions>

        @if ($clients->isEmpty())
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <div class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary">
                    <x-ui.icon name="wallet" class="h-6 w-6" />
                </div>
                <h4 class="mt-3 text-sm font-semibold">Belum ada klien toko</h4>
                <p class="mt-1 max-w-xs text-xs text-muted">Tambah klien lewat tombol "Tambah Klien" di atas.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                        <tr>
                            <th class="px-4 py-3 text-left">Nama</th>
                            <th class="px-4 py-3 text-left">Client ID</th>
                            <th class="px-4 py-3 text-center">Aktif</th>
                            <th class="px-4 py-3 text-center">Refund</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            <tr class="border-t border-border transition hover:bg-bg/60" wire:key="client-{{ $client->id }}">
                                <td class="px-4 py-3 font-medium">{{ $client->name }}</td>
                                <td class="px-4 py-3">
                                    <button type="button"
                                            x-data
                                            @click="navigator.clipboard.writeText('{{ $client->client_id }}'); $dispatch('toast', { type: 'success', message: 'Client ID disalin' })"
                                            class="inline-flex items-center gap-1 rounded-md bg-border/60 px-2 py-0.5 font-mono text-xs text-muted transition hover:text-text"
                                            title="Klik untuk salin">
                                        {{ $client->client_id }}
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button type="button" wire:click="toggleActive('{{ $client->id }}')"
                                            @class([
                                                'relative inline-flex h-5 w-9 items-center rounded-full transition',
                                                'bg-primary' => $client->is_active,
                                                'bg-border' => ! $client->is_active,
                                            ])
                                            role="switch" aria-checked="{{ $client->is_active ? 'true' : 'false' }}" aria-label="Aktif">
                                        <span @class(['inline-block h-4 w-4 transform rounded-full bg-white shadow transition', 'translate-x-4' => $client->is_active, 'translate-x-0.5' => ! $client->is_active])></span>
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button type="button" wire:click="toggleRefund('{{ $client->id }}')"
                                            @class([
                                                'relative inline-flex h-5 w-9 items-center rounded-full transition',
                                                'bg-secondary' => $client->can_refund,
                                                'bg-border' => ! $client->can_refund,
                                            ])
                                            role="switch" aria-checked="{{ $client->can_refund ? 'true' : 'false' }}" aria-label="Boleh refund">
                                        <span @class(['inline-block h-4 w-4 transform rounded-full bg-white shadow transition', 'translate-x-4' => $client->can_refund, 'translate-x-0.5' => ! $client->can_refund])></span>
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" wire:click="regenerate('{{ $client->id }}')"
                                                wire:confirm="Reset Secret? Secret lama langsung tak berlaku. Token yang sudah terbit tetap valid sampai kedaluwarsa."
                                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-warning transition hover:bg-warning/10">Reset Secret</button>
                                        @if ($canCopy)
                                            <button type="button" wire:click="openReveal('{{ $client->id }}')"
                                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted transition hover:bg-border/60 hover:text-text">Copy Kredensial</button>
                                        @endif
                                        <button type="button" wire:click="deleteClient('{{ $client->id }}')"
                                                wire:confirm="Hapus klien {{ $client->name }}? Token yang sudah terbit akan ditolak."
                                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-danger transition hover:bg-danger/10">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Modal: Tambah Klien --}}
    <div x-data="{ show: @entangle('showCreate') }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show" x-transition
             @keydown.escape.window="show = false"
             role="dialog" aria-modal="true"
             class="relative w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl">
            <h3 class="text-base font-semibold tracking-tight">Tambah Klien Toko</h3>
            <p class="mt-1 text-xs text-muted">Client ID & Secret dibuat otomatis. Secret hanya ditampilkan sekali.</p>
            <form wire:submit="createClient" class="mt-5 space-y-4">
                <x-ui.input label="Nama Toko" wire:model="newName" :error="$errors->first('newName')" />
                <label class="flex items-center gap-2 text-sm text-text">
                    <input type="checkbox" wire:model="newCanRefund" class="h-4 w-4 rounded border-border text-primary focus-visible:ring-2 focus-visible:ring-primary">
                    Boleh melakukan refund
                    <span class="text-xs text-muted">(token menyertakan ability shopping:refund)</span>
                </label>
                <div class="flex justify-end gap-3 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit">Buat Klien</x-ui.button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: Verifikasi password untuk reveal --}}
    <div x-data="{ show: @entangle('showReveal') }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show" x-transition
             @keydown.escape.window="show = false"
             role="dialog" aria-modal="true"
             class="relative w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl">
            <h3 class="text-base font-semibold tracking-tight">Copy Kredensial Klien</h3>
            <p class="mt-1 text-xs text-muted">Masukkan password akun Anda untuk menampilkan & menyalin kredensial.</p>
            <form wire:submit="confirmReveal" class="mt-5 space-y-4">
                <x-ui.input label="Password Anda" type="password" wire:model="revealPassword" autocomplete="current-password" :error="$errors->first('revealPassword')" />
                <div class="flex justify-end gap-3 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit">Verifikasi & Tampilkan</x-ui.button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: Tampilkan kredensial (sekali) --}}
    <div x-data="{ show: @entangle('showCredential') }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40"></div>
        <div x-show="show" x-transition
             role="dialog" aria-modal="true"
             class="relative w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-warning/10 text-warning">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight">Kredensial Klien — simpan sekarang</h3>
                    <p class="mt-1 text-xs text-muted">@if($credIsNew)Secret hanya ditampilkan sekali ini.@else Salin dan simpan di tempat aman.@endif</p>
                </div>
            </div>

            <div class="mt-4 space-y-3" x-data="{ copied: false }">
                <div>
                    <p class="text-xs font-medium text-muted">Client ID</p>
                    <p class="mt-0.5 break-all rounded-lg bg-bg px-3 py-2 font-mono text-sm">{{ $credClientId }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-muted">Client Secret</p>
                    <p class="mt-0.5 break-all rounded-lg bg-bg px-3 py-2 font-mono text-sm">{{ $credSecret }}</p>
                </div>
                <x-ui.button variant="secondary" class="w-full"
                             @click="navigator.clipboard.writeText('Client ID: {{ $credClientId }}\nClient Secret: {{ $credSecret }}'); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-show="!copied">Salin Kredensial</span>
                    <span x-show="copied" x-cloak>Tersalin ✓</span>
                </x-ui.button>
            </div>

            <div class="mt-5 flex justify-end">
                <x-ui.button @click="$wire.closeCredential()">Selesai</x-ui.button>
            </div>
        </div>
    </div>
</div>
