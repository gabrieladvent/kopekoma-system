<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="academic-cap" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Master Data Golongan</h2>
                <p class="mt-0.5 text-sm text-muted">Kelola golongan kepegawaian beserta nominal simpanan wajib per bulan.</p>
            </div>
        </div>

        @can('create_grade')
            <x-ui.button wire:click="create" class="shrink-0">
                <x-ui.icon name="plus" class="h-4.5 w-4.5" />
                Tambah Golongan
            </x-ui.button>
        @endcan
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="relative w-full sm:max-w-xs">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari kode atau nama…"
                   class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        </div>

        <div class="inline-flex items-center gap-1 rounded-xl border border-border bg-surface p-1 text-sm">
            @foreach (['all' => 'Semua', 'active' => 'Aktif', 'inactive' => 'Nonaktif'] as $value => $label)
                <button type="button" wire:click="$set('status', '{{ $value }}')"
                        @class([
                            'rounded-lg px-3 py-1.5 font-medium transition duration-150 ease-out',
                            'bg-primary/10 text-primary' => $status === $value,
                            'text-muted hover:text-text' => $status !== $value,
                        ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Tabel --}}
    <x-ui.card class="p-0">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full text-sm">
                <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-5 py-3 text-left">Kode</th>
                        <th class="px-5 py-3 text-left">Nama Golongan</th>
                        <th class="px-5 py-3 text-right">Simpanan Wajib / Bln</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="w-12 px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>

                {{-- Skeleton saat loading filter/paging --}}
                <tbody wire:loading.delay class="divide-y divide-border">
                    @for ($i = 0; $i < 5; $i++)
                        <tr>
                            <td class="px-5 py-4"><div class="h-5 w-16 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-4 w-40 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-4 w-24 animate-pulse rounded bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="h-5 w-16 animate-pulse rounded-full bg-border/60"></div></td>
                            <td class="px-5 py-4"><div class="ml-auto h-8 w-8 animate-pulse rounded-lg bg-border/60"></div></td>
                        </tr>
                    @endfor
                </tbody>

                <tbody wire:loading.remove class="divide-y divide-border">
                    @forelse ($grades as $grade)
                        <tr class="transition hover:bg-bg/60" wire:key="grade-{{ $grade->id }}">
                            <td class="px-5 py-4">
                                <x-ui.badge color="primary" class="font-mono">{{ $grade->code }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 font-medium text-text">{{ $grade->name }}</td>
                            <td class="px-5 py-4 text-right font-semibold tabular-nums text-text">
                                Rp {{ number_format((int) $grade->mandatory_savings_amount, 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$grade->is_active ? 'success' : 'neutral'">
                                    {{ $grade->is_active ? 'Aktif' : 'Nonaktif' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-ui.dropdown>
                                        <x-ui.dropdown-item icon="eye" wire:click="show({{ $grade->id }})">Lihat Detail</x-ui.dropdown-item>

                                        @can('update_grade')
                                            <x-ui.dropdown-item icon="pencil" wire:click="edit({{ $grade->id }})">Edit</x-ui.dropdown-item>
                                            <x-ui.dropdown-item icon="power" wire:click="toggleActive({{ $grade->id }})">
                                                {{ $grade->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                            </x-ui.dropdown-item>
                                        @endcan

                                        @can('delete_grade')
                                            <div class="my-1 border-t border-border"></div>
                                            <x-ui.dropdown-item icon="trash" variant="danger"
                                                x-on:click="$dispatch('confirm-action', {
                                                    title: 'Hapus golongan {{ $grade->name }}?',
                                                    message: 'Tindakan ini permanen dan tidak dapat dibatalkan.',
                                                    confirmLabel: 'Hapus', variant: 'danger',
                                                    method: 'delete', params: [{{ $grade->id }}],
                                                })">Hapus</x-ui.dropdown-item>
                                        @endcan
                                    </x-ui.dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary">
                                        <x-ui.icon name="academic-cap" class="h-7 w-7" />
                                    </div>
                                    <h4 class="mt-4 text-sm font-semibold text-text">
                                        {{ $search !== '' || $status !== 'all' ? 'Tidak ada golongan yang cocok' : 'Belum ada golongan' }}
                                    </h4>
                                    <p class="mt-1 max-w-xs text-xs text-muted">
                                        {{ $search !== '' || $status !== 'all'
                                            ? 'Coba ubah kata kunci atau filter status.'
                                            : 'Mulai dengan menambahkan golongan kepegawaian pertama.' }}
                                    </p>
                                    @can('create_grade')
                                        @if ($search === '' && $status === 'all')
                                            <x-ui.button wire:click="create" class="mt-4 h-9 px-3">
                                                <x-ui.icon name="plus" class="h-4 w-4" /> Tambah Golongan
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

        @if ($grades->hasPages())
            <div class="border-t border-border px-5 py-3">
                {{ $grades->links() }}
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
             class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
            <h3 class="text-base font-semibold tracking-tight text-text">
                {{ $editingId ? 'Edit Golongan' : 'Tambah Golongan' }}
            </h3>
            <p class="mt-1 text-xs text-muted">Lengkapi data golongan kepegawaian di bawah ini.</p>

            <form wire:submit="save" class="mt-5 space-y-4">
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
                <x-ui.input label="Nama Golongan" wire:model="name" placeholder="Golongan I" :error="$errors->first('name')" />

                {{-- Simpanan wajib (format ribuan via Alpine, simpan integer) --}}
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
                    <span class="text-xs text-muted">(nonaktifkan bila golongan tidak lagi dipakai)</span>
                </label>

                <div class="flex justify-end gap-3 pt-2">
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

    {{-- Modal: Detail (info + audit trail). .live agar tutup client langsung sinkron ke server. --}}
    <div x-data="{ show: @entangle('showDetail').live }" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @keydown.escape.window="show = false"
             role="dialog" aria-modal="true"
             class="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-xl">
            @if ($detail)
                <div class="flex items-start justify-between gap-4 border-b border-border px-6 py-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <x-ui.badge color="primary" class="font-mono">{{ $detail->code }}</x-ui.badge>
                            <x-ui.badge :color="$detail->is_active ? 'success' : 'neutral'">{{ $detail->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                        </div>
                        <h3 class="mt-2 text-lg font-bold tracking-tight text-text">{{ $detail->name }}</h3>
                    </div>
                    <button type="button" @click="show = false" aria-label="Tutup"
                            class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text">
                        <x-ui.icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                {{-- Tabs --}}
                <div class="flex gap-6 border-b border-border px-6">
                    @foreach (['info' => 'Info', 'audit' => 'Audit Trail'] as $tab => $label)
                        <button type="button" wire:click="$set('detailTab', '{{ $tab }}')"
                                @class([
                                    '-mb-px border-b-2 py-3 text-sm font-medium transition',
                                    'border-primary text-primary' => $detailTab === $tab,
                                    'border-transparent text-muted hover:text-text' => $detailTab !== $tab,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>

                <div class="overflow-y-auto p-6">
                    {{-- Tab Info --}}
                    @if ($detailTab === 'info')
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-5">
                            <div class="col-span-2">
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Simpanan Wajib / Bulan</dt>
                                <dd class="mt-1 text-3xl font-bold tabular-nums text-text">Rp {{ number_format((int) $detail->mandatory_savings_amount, 0, ',', '.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Jumlah Anggota</dt>
                                <dd class="mt-1 text-sm font-semibold text-text">{{ number_format($detail->members_count, 0, ',', '.') }} anggota</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Status</dt>
                                <dd class="mt-1 text-sm font-medium text-text">{{ $detail->is_active ? 'Aktif' : 'Nonaktif' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Dibuat</dt>
                                <dd class="mt-1 text-sm text-text">{{ $detail->created_at?->translatedFormat('d M Y H:i') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted">Diperbarui</dt>
                                <dd class="mt-1 text-sm text-text">{{ $detail->updated_at?->translatedFormat('d M Y H:i') }}</dd>
                            </div>
                        </dl>
                    @else
                        {{-- Tab Audit Trail --}}
                        @if ($activities->isEmpty())
                            <div class="flex flex-col items-center justify-center py-10 text-center">
                                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-border/60 text-muted">
                                    <x-ui.icon name="clipboard" class="h-6 w-6" />
                                </div>
                                <p class="mt-3 text-sm text-muted">Belum ada aktivitas tercatat.</p>
                            </div>
                        @else
                            <ol class="relative space-y-5 border-l border-border pl-5">
                                @foreach ($activities as $activity)
                                    @php($color = \App\Livewire\Master\Grades::EVENT_COLORS[$activity->event] ?? 'neutral')
                                    <li class="relative" wire:key="act-{{ $activity->id }}">
                                        <span @class([
                                            'absolute -left-[1.4rem] top-1 h-2.5 w-2.5 rounded-full ring-4 ring-surface',
                                            'bg-success' => $color === 'success',
                                            'bg-warning' => $color === 'warning',
                                            'bg-danger' => $color === 'danger',
                                            'bg-primary' => $color === 'primary',
                                            'bg-muted' => $color === 'neutral',
                                        ])></span>
                                        <div class="flex items-center gap-2">
                                            <x-ui.badge :color="$color">{{ \App\Livewire\Master\Grades::EVENT_LABELS[$activity->event] ?? ucfirst((string) $activity->event) }}</x-ui.badge>
                                            <span class="text-xs text-muted">{{ $activity->created_at?->translatedFormat('d M Y H:i') }}</span>
                                        </div>
                                        @if ($activity->description)
                                            <p class="mt-1 text-sm text-text">{{ $activity->description }}</p>
                                        @endif
                                        <p class="mt-0.5 text-xs text-muted">oleh {{ $activity->causer?->name ?? 'Sistem' }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Singletons --}}
    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
