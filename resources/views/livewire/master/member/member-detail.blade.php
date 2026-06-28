@php($statusColor = match ($member->status) {
    'Aktif' => 'success',
    'Keluar' => 'warning',
    'Meninggal' => 'danger',
    default => 'neutral',
})
@php($canImport = $this->canManageImportExport())
<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('master.members') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar anggota
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-secondary/15 text-base font-semibold text-secondary">
                {{ \Illuminate\Support\Str::of($member->full_name)->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('') }}
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $member->member_number }}</x-ui.badge>
                    <x-ui.badge :color="$statusColor">{{ $member->status }}</x-ui.badge>
                    <x-ui.badge color="neutral" class="font-mono">{{ $member->grade?->code ?? '—' }}</x-ui.badge>
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $member->full_name }}</h2>
                <p class="mt-0.5 text-sm text-muted">{{ $member->position ? $member->position.' · ' : '' }}{{ $member->agency?->agency_name }}</p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @can('update_member')
                <x-ui.button variant="ghost" :href="route('master.members.edit', $member)" wire:navigate>
                    <x-ui.icon name="pencil" class="h-4 w-4" /> Edit
                </x-ui.button>
            @endcan
            @if ($canImport)
                <x-ui.button variant="ghost" :href="route('master.members.card', $member)">
                    <x-ui.icon name="printer" class="h-4 w-4" /> Cetak Kartu
                </x-ui.button>
            @endif
            @can('delete_member')
                <x-ui.button variant="danger"
                    x-on:click="$dispatch('confirm-action', {
                        title: 'Hapus anggota {{ $member->full_name }}?',
                        message: 'Data dipindah ke arsip (soft delete) dan tidak tampil di daftar.',
                        confirmLabel: 'Hapus', variant: 'danger',
                        method: 'delete', params: [],
                    })">
                    <x-ui.icon name="trash" class="h-4 w-4" /> Hapus
                </x-ui.button>
            @endcan
        </div>
    </div>

    {{-- Profil --}}
    <x-ui.card>
        {{-- Simpanan wajib highlight --}}
        <div class="rounded-xl bg-bg px-4 py-3">
            <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                <x-ui.icon name="cash" class="h-3.5 w-3.5" /> Simpanan Wajib / Bulan (snapshot golongan)
            </p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-text">Rp {{ number_format((int) $member->mandatory_savings_amount, 0, ',', '.') }}</p>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-8 md:grid-cols-2">
            {{-- Data pribadi --}}
            <div>
                <h3 class="flex items-center gap-2 border-b border-border pb-2 text-sm font-semibold text-text">
                    <x-ui.icon name="identification" class="h-4.5 w-4.5 text-primary" /> Data Pribadi
                </h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-muted">NIK</dt><dd class="font-mono text-text">{{ $member->nik }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">NIP</dt><dd class="text-text">{{ $member->nip ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Tempat, Tgl Lahir</dt><dd class="text-right text-text">{{ $member->birth_place }}, {{ $member->birth_date?->translatedFormat('d M Y') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Jenis Kelamin</dt><dd class="text-text">{{ $member->gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</dd></div>
                </dl>
            </div>

            {{-- Kepegawaian --}}
            <div>
                <h3 class="flex items-center gap-2 border-b border-border pb-2 text-sm font-semibold text-text">
                    <x-ui.icon name="briefcase" class="h-4.5 w-4.5 text-primary" /> Kepegawaian
                </h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-muted">OPD / Instansi</dt><dd class="text-right text-text">{{ $member->agency?->agency_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Golongan</dt><dd class="text-right text-text">{{ $member->grade ? $member->grade->code.' — '.$member->grade->name : '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Jabatan</dt><dd class="text-text">{{ $member->position ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Status Kepegawaian</dt><dd><x-ui.badge color="neutral">{{ $member->employment_status }}</x-ui.badge></dd></div>
                </dl>
            </div>

            {{-- Kontak & rekening --}}
            <div>
                <h3 class="flex items-center gap-2 border-b border-border pb-2 text-sm font-semibold text-text">
                    <x-ui.icon name="phone" class="h-4.5 w-4.5 text-primary" /> Kontak & Rekening
                </h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-muted">No. HP</dt><dd class="text-text">{{ $member->phone_number ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">No. Rekening Gaji</dt><dd class="font-mono text-text">{{ $member->payroll_account_number }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Bank</dt><dd class="text-text">{{ $member->bank_name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Alamat</dt><dd class="max-w-[60%] text-right text-text">{{ $member->address }}</dd></div>
                </dl>
            </div>

            {{-- Ahli waris & keanggotaan --}}
            <div>
                <h3 class="flex items-center gap-2 border-b border-border pb-2 text-sm font-semibold text-text">
                    <x-ui.icon name="heart" class="h-4.5 w-4.5 text-primary" /> Ahli Waris & Keanggotaan
                </h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-muted">Ahli Waris</dt><dd class="text-right text-text">{{ $member->heir_name }} <span class="text-muted">({{ $member->heir_relationship }})</span></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">No. HP Ahli Waris</dt><dd class="text-text">{{ $member->heir_phone_number ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Tgl Bergabung</dt><dd class="text-text">{{ $member->join_date?->translatedFormat('d M Y') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Tgl Keluar</dt><dd class="text-text">{{ $member->exit_date?->translatedFormat('d M Y') ?: '—' }}</dd></div>
                </dl>
            </div>
        </div>
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Dokumen --}}
        <x-ui.card>
            <div class="flex items-center justify-between border-b border-border pb-3">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-text">
                    <x-ui.icon name="paper-clip" class="h-4.5 w-4.5 text-primary" /> Dokumen
                </h3>
                <span class="text-xs text-muted">{{ $documents->count() }} berkas</span>
            </div>

            <div class="mt-2 divide-y divide-border">
                @forelse ($documents as $doc)
                    <div class="flex items-center gap-3 py-3" wire:key="doc-{{ $doc->id }}">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-bg text-muted">
                            <x-ui.icon name="document" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-text">{{ $doc->file_name }}</p>
                            <p class="text-xs text-muted">{{ $doc->human_readable_size }} · {{ $doc->created_at?->translatedFormat('d M Y H:i') }}</p>
                        </div>
                        <a href="{{ $doc->getFullUrl() }}" target="_blank" rel="noopener" title="Lihat"
                           class="grid h-8 w-8 place-items-center rounded-lg text-muted transition hover:bg-border/50 hover:text-text">
                            <x-ui.icon name="eye" class="h-4.5 w-4.5" />
                        </a>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        <div class="grid h-12 w-12 place-items-center rounded-2xl bg-border/60 text-muted">
                            <x-ui.icon name="paper-clip" class="h-6 w-6" />
                        </div>
                        <p class="mt-3 text-sm text-muted">Belum ada dokumen.</p>
                        @can('update_member')
                            <a href="{{ route('master.members.edit', $member) }}" wire:navigate
                               class="mt-2 text-xs font-medium text-primary hover:underline">Tambah lewat halaman Edit</a>
                        @endcan
                    </div>
                @endforelse
            </div>

            @can('update_member')
                @if ($documents->isNotEmpty())
                    <div class="mt-4 border-t border-border pt-3">
                        <a href="{{ route('master.members.edit', $member) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 text-xs font-medium text-muted transition hover:text-primary">
                            <x-ui.icon name="pencil" class="h-3.5 w-3.5" /> Kelola dokumen di halaman Edit
                        </a>
                    </div>
                @endif
            @endcan
        </x-ui.card>

        {{-- Audit Trail --}}
        <x-ui.card>
            <div class="flex items-center justify-between">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-text">
                    <x-ui.icon name="clipboard" class="h-4.5 w-4.5 text-primary" /> Audit Trail
                </h3>
                <span class="text-xs text-muted">Klik baris untuk detail</span>
            </div>
            <div class="mt-4">
                @include('livewire.master.partials.audit-trail')
            </div>
        </x-ui.card>
    </div>

    {{-- Singletons --}}
    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
