<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary/15 text-primary">
                <x-ui.icon name="chart" class="h-6 w-6" />
            </span>
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-text">Laporan Setoran Simpanan</h2>
                <p class="mt-0.5 text-sm text-muted">Detail setoran per periode, net of reversals. Difilter lalu diekspor PDF/Excel.</p>
            </div>
        </div>

        @if ($this->canExport())
            <div class="flex items-center gap-2">
                <button type="button" wire:click="exportExcel" wire:loading.attr="disabled"
                        class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 text-sm font-medium text-text transition hover:bg-success/10 hover:text-success disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-success focus-visible:outline-none">
                    <x-ui.icon name="arrow-down-tray" class="h-4 w-4" /> Excel
                </button>
                <button type="button" wire:click="exportPdf" wire:loading.attr="disabled"
                        class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 text-sm font-medium text-text transition hover:bg-danger/10 hover:text-danger disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-danger focus-visible:outline-none">
                    <x-ui.icon name="printer" class="h-4 w-4" /> PDF
                </button>
            </div>
        @endif
    </div>

    {{-- Filter --}}
    <x-ui.card>
        <form wire:submit="generate" class="space-y-5">
            <div class="flex items-center gap-2 text-sm font-semibold text-text">
                <x-ui.icon name="funnel" class="h-4 w-4 text-muted" /> Filter Laporan
            </div>

            {{-- Basis periode --}}
            <div>
                <label class="mb-1.5 block text-sm font-medium text-text">Basis Periode</label>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border bg-bg p-3 transition has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                        <input type="radio" wire:model="basis" value="period_month" class="mt-0.5 accent-primary">
                        <span class="text-sm">
                            <span class="block font-medium text-text">Periode Potong Gaji</span>
                            <span class="text-xs text-muted">Rekonsiliasi payroll (default)</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border bg-bg p-3 transition has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                        <input type="radio" wire:model="basis" value="deposit_date" class="mt-0.5 accent-primary">
                        <span class="text-sm">
                            <span class="block font-medium text-text">Tanggal Setor</span>
                            <span class="text-xs text-muted">Sukarela / hari raya</span>
                        </span>
                    </label>
                </div>
                <p class="mt-1.5 text-xs text-muted">Basis <strong>Periode Potong Gaji</strong> mengecualikan setoran tanpa periode (mis. sukarela/hari raya). Untuk jenis tersebut pakai <strong>Tanggal Setor</strong>.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Tanggal --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-text">Dari Tanggal</label>
                    <input type="date" wire:model="start"
                           class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                    @error('start') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-text">Sampai Tanggal</label>
                    <input type="date" wire:model="end"
                           class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                    @error('end') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                {{-- Metode --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-text">Metode Setor</label>
                    <select wire:model="deposit_method"
                            class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <option value="">Semua metode</option>
                        @foreach ($depositMethods as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('deposit_method') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                {{-- OPD --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-text">OPD / Instansi</label>
                    <select wire:model="agency_id"
                            class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-text transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        <option value="">Semua OPD</option>
                        @foreach ($agencyOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Jenis simpanan (checkbox) --}}
            <div>
                <label class="mb-1.5 block text-sm font-medium text-text">Jenis Simpanan <span class="font-normal text-muted">(kosong = semua)</span></label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($savingsTypes as $val => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-bg px-3 py-2 text-sm transition has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                            <input type="checkbox" wire:model="savings_type" value="{{ $val }}" class="accent-primary">
                            <span class="text-text">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Anggota (picker) --}}
            <div>
                <label class="mb-1.5 block text-sm font-medium text-text">Anggota <span class="font-normal text-muted">(opsional)</span></label>
                @if ($member_id)
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/10 px-3 py-2 text-sm text-primary">
                            <x-ui.icon name="user" class="h-4 w-4" /> {{ $memberLabel }}
                        </span>
                        <button type="button" wire:click="clearMember"
                                class="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-sm text-danger transition hover:bg-danger/10">
                            <x-ui.icon name="x" class="h-4 w-4" /> Ganti
                        </button>
                    </div>
                @else
                    <div class="relative">
                        <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
                        <input type="text" wire:model.live.debounce.300ms="memberSearch" placeholder="Cari nama atau no. anggota…"
                               class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                        @if (mb_strlen(trim($memberSearch)) >= 2)
                            <div class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-border bg-surface shadow-lg">
                                @forelse ($this->memberResults as $m)
                                    <button type="button" wire:key="mres-{{ $m->id }}" wire:click="selectMember('{{ $m->id }}')"
                                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition hover:bg-bg">
                                        <span class="text-text">{{ $m->full_name }}</span>
                                        <span class="font-mono text-xs text-muted">{{ $m->member_number }}</span>
                                    </button>
                                @empty
                                    <p class="px-3 py-2 text-sm text-muted">Tidak ada anggota cocok.</p>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex justify-end border-t border-border pt-4">
                <button type="submit" wire:loading.attr="disabled"
                        class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none">
                    <x-ui.icon name="search" class="h-4 w-4" wire:loading.remove wire:target="generate" />
                    <svg wire:loading wire:target="generate" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Tampilkan
                </button>
            </div>
        </form>
    </x-ui.card>

    {{-- Hasil --}}
    @if ($appliedFilters !== null)
        @if (($appliedFilters['basis'] ?? null) === \App\Services\DepositReportService::BASIS_PERIOD_MONTH)
            <div class="flex items-start gap-2 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-text">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning" />
                <p>Basis <strong>Periode Potong Gaji</strong>: setoran tanpa periode (mis. sukarela / hari raya) tidak ikut ditampilkan. Untuk jenis tersebut gunakan basis <strong>Tanggal Setor</strong>.</p>
            </div>
        @endif

        <x-ui.card class="p-0">
            <div class="flex flex-col gap-1 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-text">Hasil Laporan</h3>
                    <p class="text-xs text-muted">{{ $rows->count() }} baris (termasuk reversal). Nominal = net of reversals.</p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-muted">Grand Total (net)</p>
                    <p class="text-lg font-bold tabular-nums text-primary">Rp {{ number_format((float) $total, 0, ',', '.') }}</p>
                </div>
            </div>

            @if ($rows->isEmpty())
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center">
                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/10 text-primary">
                        <x-ui.icon name="chart" class="h-7 w-7" />
                    </div>
                    <h4 class="mt-4 text-sm font-semibold text-text">Tidak ada data</h4>
                    <p class="mt-1 max-w-xs text-xs text-muted">Tidak ada setoran untuk filter ini. Coba ubah rentang tanggal atau basis periode.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-bg text-xs font-medium uppercase tracking-wide text-muted">
                            <tr>
                                <th class="px-5 py-3 text-left">Tanggal</th>
                                <th class="px-5 py-3 text-left">No. Transaksi</th>
                                <th class="px-5 py-3 text-left">No. Anggota</th>
                                <th class="px-5 py-3 text-left">Nama</th>
                                <th class="px-5 py-3 text-left">OPD</th>
                                <th class="px-5 py-3 text-left">Jenis</th>
                                <th class="px-5 py-3 text-left">Metode</th>
                                <th class="px-5 py-3 text-right">Nominal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($rows as $row)
                                <tr @class(['transition hover:bg-bg/60', 'text-danger' => $row->is_reversal])>
                                    <td class="whitespace-nowrap px-5 py-3">{{ optional($row->deposit_date)->format('d/m/Y') }}</td>
                                    <td class="whitespace-nowrap px-5 py-3 font-mono text-xs">{{ $row->transaction_number }}</td>
                                    <td class="whitespace-nowrap px-5 py-3 font-mono text-xs">{{ $row->member?->member_number }}</td>
                                    <td class="px-5 py-3">{{ $row->member?->full_name }}</td>
                                    <td class="px-5 py-3">{{ $row->member?->agency?->agency_name ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-5 py-3">{{ $this->savingsTypeLabel($row->savings_type) }}</td>
                                    <td class="whitespace-nowrap px-5 py-3">
                                        {{ $this->depositMethodLabel($row->deposit_method) }}
                                        @if ($row->is_reversal)
                                            <span class="text-xs">(reversal)</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">Rp {{ number_format((float) $row->signed_amount, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-border bg-bg font-semibold">
                                <td class="px-5 py-3" colspan="7">Grand Total (net)</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums text-primary">Rp {{ number_format((float) $total, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.toast-host />
</div>
