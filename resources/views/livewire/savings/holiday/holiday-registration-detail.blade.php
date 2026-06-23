<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('savings.holiday') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-warning/10 text-warning">
                <x-ui.icon name="gift" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge color="warning" class="font-mono">Tahun {{ $holiday->period_year }}</x-ui.badge>
                    <x-ui.badge :color="$holiday->is_active ? 'success' : 'neutral'">{{ $holiday->is_active ? 'Aktif' : 'Non-Aktif' }}</x-ui.badge>
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">{{ $holiday->member?->full_name ?? '—' }}</h2>
                <p class="font-mono text-sm text-muted">{{ $holiday->member?->member_number }}</p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @can('update_member::holiday::saving')
                <x-ui.button variant="ghost" :href="route('savings.holiday.edit', $holiday)" wire:navigate>
                    <x-ui.icon name="pencil" class="h-4 w-4" /> Edit
                </x-ui.button>
            @endcan
            @can('delete_member::holiday::saving')
                <x-ui.button variant="danger"
                    x-on:click="$dispatch('confirm-action', {
                        title: 'Hapus pendaftaran ini?',
                        message: 'Registrasi nominal Hari Raya {{ $holiday->period_year }} untuk {{ $holiday->member?->full_name }} akan dihapus. Setoran yang sudah tercatat tidak terpengaruh.',
                        confirmLabel: 'Hapus', variant: 'danger',
                        method: 'delete', params: [],
                    })">
                    <x-ui.icon name="trash" class="h-4 w-4" /> Hapus
                </x-ui.button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Info + saldo --}}
            <x-ui.card>
                <h3 class="text-sm font-semibold text-text">Informasi Registrasi</h3>

                {{-- Highlight: nominal & saldo --}}
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-bg px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted">Nominal Bulanan</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-text">Rp {{ number_format((float) $holiday->monthly_amount, 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl bg-primary/5 px-4 py-3 ring-1 ring-inset ring-primary/15">
                        <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-primary">
                            <x-ui.icon name="wallet" class="h-3.5 w-3.5" /> Saldo Terkumpul
                        </p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-primary">Rp {{ number_format((float) $balance, 0, ',', '.') }}</p>
                        <p class="mt-0.5 text-xs text-muted">Total setoran dikurangi pencairan tahun ini.</p>
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5 border-t border-border pt-5">
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="building-office" class="h-3.5 w-3.5" /> OPD / Instansi
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $holiday->member?->agency?->agency_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="academic-cap" class="h-3.5 w-3.5" /> Golongan
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $holiday->member?->grade?->code ?? '—' }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Periode Pengumpulan
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ $holiday->start_date?->translatedFormat('d M Y') ?? '—' }}
                            <span class="text-muted">s/d</span>
                            {{ $holiday->end_date?->translatedFormat('d M Y') ?? '—' }}
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="document" class="h-3.5 w-3.5" /> Catatan
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $holiday->notes ?: '—' }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Rekap setoran --}}
            <x-ui.card class="p-0">
                <div class="flex items-center justify-between gap-4 border-b border-border px-6 py-4">
                    <div class="flex items-center gap-2">
                        <x-ui.icon name="banknotes" class="h-5 w-5 text-primary" />
                        <h3 class="text-sm font-semibold text-text">Setoran Hari Raya {{ $holiday->period_year }}</h3>
                    </div>
                    <span class="text-xs text-muted">Read-only · koreksi via reversal di modul Setoran</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                            <tr>
                                <th class="px-6 py-3 text-left">No. Transaksi</th>
                                <th class="px-6 py-3 text-left">Tanggal</th>
                                <th class="px-6 py-3 text-right">Nominal</th>
                                <th class="px-6 py-3 text-center">Reversal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($deposits as $deposit)
                                <tr wire:key="dep-{{ $deposit->id }}" class="transition hover:bg-bg/60">
                                    <td class="px-6 py-3 font-mono text-xs text-text">{{ $deposit->transaction_number }}</td>
                                    <td class="px-6 py-3 text-text">{{ $deposit->deposit_date?->translatedFormat('d M Y') }}</td>
                                    <td class="px-6 py-3 text-right tabular-nums {{ $deposit->is_reversal ? 'text-danger' : 'text-text' }}">
                                        {{ $deposit->is_reversal ? '−' : '' }}Rp {{ number_format((float) $deposit->amount, 0, ',', '.') }}
                                    </td>
                                    <td class="px-6 py-3 text-center">
                                        @if ($deposit->is_reversal)
                                            <x-ui.badge color="danger">Reversal</x-ui.badge>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center">
                                        <p class="text-sm text-muted">Belum ada setoran Hari Raya untuk tahun program ini.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($deposits->hasPages())
                    <div class="border-t border-border px-6 py-3">
                        {{ $deposits->links() }}
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- Audit Trail --}}
        <div>
            <x-ui.card>
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-text">Audit Trail</h3>
                    <span class="text-xs text-muted">Klik untuk detail</span>
                </div>
                <div class="mt-4">
                    @include('livewire.master.partials.audit-trail')
                </div>
            </x-ui.card>
        </div>
    </div>

    <x-ui.confirm-modal />
    <x-ui.toast-host />
</div>
