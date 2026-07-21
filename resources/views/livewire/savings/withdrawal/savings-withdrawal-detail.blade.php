@php
    $typeBadge = match ($typeColor) {
        'warning' => 'warning',
        'success' => 'success',
        default => 'neutral',
    };
    // Warna status di-drive enum WithdrawalStatus::color() (palet x-ui.badge).
    $statusBadge = $withdrawal->status->color();

    // State machine view: draft → acc → cair (atau ditolak). State per langkah
    // dihitung dari timestamp (approved_at/disbursed_at) agar tahan urutan.
    $status = $withdrawal->status;
    $rejected = $status === \App\Enums\WithdrawalStatus::Ditolak;
    $accDone = $withdrawal->approved_at !== null;
    $cairDone = $withdrawal->disbursed_at !== null;

    $steps = [
        [
            'label' => 'Diajukan',
            'desc' => 'Pengajuan dibuat sebagai draft.',
            'icon' => 'document',
            'state' => 'done',
            'time' => $withdrawal->created_at,
            'who' => $withdrawal->recordedBy?->name,
        ],
        [
            'label' => 'Disetujui (ACC)',
            'desc' => 'Mata kedua sebelum dana keluar.',
            'icon' => 'check',
            'state' => $accDone
                ? 'done'
                : ($status === \App\Enums\WithdrawalStatus::Draft && !$rejected
                    ? 'current'
                    : 'upcoming'),
            'time' => $withdrawal->approved_at,
            'who' => $withdrawal->approvedBy?->name,
        ],
        [
            'label' => 'Dana Cair',
            'desc' => 'Saldo anggota berkurang.',
            'icon' => 'banknotes',
            'state' => $cairDone
                ? 'done'
                : ($status === \App\Enums\WithdrawalStatus::Acc && !$rejected
                    ? 'current'
                    : 'upcoming'),
            'time' => $withdrawal->disbursed_at,
            'who' => null,
        ],
    ];

    $node = [
        'done' => ['ring' => 'bg-success text-white', 'line' => 'bg-success', 'label' => 'text-text'],
        'current' => [
            'ring' => 'border-2 border-primary bg-primary/10 text-primary',
            'line' => 'bg-border',
            'label' => 'text-text',
        ],
        'upcoming' => [
            'ring' => 'border-2 border-border bg-surface text-muted',
            'line' => 'bg-border',
            'label' => 'text-muted',
        ],
    ];
@endphp

<div class="space-y-6">
    {{-- Back --}}
    <a href="{{ route('savings.withdrawals') }}" wire:navigate
        class="inline-flex items-center gap-1.5 text-sm font-medium text-muted transition hover:text-text">
        <x-ui.icon name="arrow-left" class="h-4 w-4" />
        Kembali ke daftar
    </a>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                <x-ui.icon name="arrow-up-tray" class="h-6 w-6" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge color="primary" class="font-mono">{{ $withdrawal->withdrawal_number }}</x-ui.badge>
                    <x-ui.badge :color="$typeBadge">{{ $typeLabel }}</x-ui.badge>
                    <x-ui.badge :color="$statusBadge">{{ $statusLabel }}</x-ui.badge>
                    @if ($withdrawal->is_reversal)
                        <x-ui.badge color="danger">Reversal</x-ui.badge>
                    @endif
                </div>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-text">
                    {{ $withdrawal->member?->full_name ?? '—' }}</h2>
                <p class="font-mono text-sm text-muted">{{ $withdrawal->member?->member_number }}</p>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($this->canApprove($withdrawal))
                <x-ui.button wire:click="openConfirm('approve')">
                    <x-ui.icon name="check" class="h-4 w-4" /> Setujui (ACC)
                </x-ui.button>
            @endif
            @if ($this->canDisburse($withdrawal))
                <x-ui.button wire:click="openConfirm('disburse')">
                    <x-ui.icon name="banknotes" class="h-4 w-4" /> Cairkan Dana
                </x-ui.button>
            @endif
            @if ($this->canReject($withdrawal))
                <x-ui.button variant="danger" wire:click="openConfirm('reject')">
                    <x-ui.icon name="x" class="h-4 w-4" /> Tolak
                </x-ui.button>
            @endif
            @if ($this->canReverse($withdrawal))
                <x-ui.button variant="danger" wire:click="openReverse">
                    <x-ui.icon name="arrow-uturn-left" class="h-4 w-4" /> Reversal
                </x-ui.button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- KIRI: nominal + rincian --}}
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card>
                <div
                    class="rounded-xl px-4 py-4 {{ $withdrawal->is_reversal ? 'bg-success/5 ring-1 ring-inset ring-success/15' : 'bg-primary/5 ring-1 ring-inset ring-primary/15' }}">
                    <p
                        class="text-xs font-medium uppercase tracking-wide {{ $withdrawal->is_reversal ? 'text-success' : 'text-primary' }}">
                        {{ $withdrawal->is_reversal ? 'Nominal Dikoreksi' : 'Nominal Pencairan' }}
                    </p>
                    <p
                        class="mt-1 text-3xl font-bold tabular-nums {{ $withdrawal->is_reversal ? 'text-success' : 'text-primary' }}">
                        {{ $withdrawal->is_reversal ? '+' : '−' }}Rp
                        {{ number_format((float) ($isRefund && !$withdrawal->is_reversal ? $refundTotal : $withdrawal->amount), 0, ',', '.') }}
                    </p>
                    @if ($isRefund && !$withdrawal->is_reversal)
                        <p class="mt-1 text-xs text-muted">
                            SWP Rp {{ number_format((float) $refundSwp, 0, ',', '.') }}
                            · Tab. Berjangka Rp {{ number_format((float) $refundTab, 0, ',', '.') }}
                        </p>
                    @endif
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5">
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Tanggal Pengajuan
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ $withdrawal->withdrawal_date?->translatedFormat('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="banknotes" class="h-3.5 w-3.5" /> Jenis Pencairan
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ \App\Filament\Resources\SavingsWithdrawalResource::DISBURSEMENT_METHODS[$withdrawal->disbursement_method] ?? '—' }}
                        </dd>
                    </div>
                    @if ($withdrawal->disbursement_method === 'transfer')
                        <div>
                            <dt
                                class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                                <x-ui.icon name="banknotes" class="h-3.5 w-3.5" /> Rekening Tujuan
                            </dt>
                            <dd class="mt-1 text-sm text-text">
                                @if (filled($withdrawal->member?->payroll_account_number))
                                    <span class="font-medium">{{ $withdrawal->member->bank_name ?: 'Bank —' }}</span>
                                    <span
                                        class="ml-1 font-mono">{{ $withdrawal->member->payroll_account_number }}</span>
                                @else
                                    <span class="text-warning">Belum ada data rekening anggota.</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="gift" class="h-3.5 w-3.5" /> Tahun Program
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $withdrawal->period_year ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="building-office" class="h-3.5 w-3.5" /> OPD / Instansi
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $withdrawal->member?->agency?->agency_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="user" class="h-3.5 w-3.5" /> Diajukan Oleh
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $withdrawal->recordedBy?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="calendar" class="h-3.5 w-3.5" /> Dicatat Pada
                        </dt>
                        <dd class="mt-1 text-sm text-text">
                            {{ $withdrawal->created_at?->translatedFormat('d M Y · H.i') }} WIB</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="check" class="h-3.5 w-3.5" /> Disetujui Oleh
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $withdrawal->approvedBy?->name ?? '—' }}</dd>
                    </div>
                    @if ($withdrawal->is_reversal && $withdrawal->reversalOf)
                        <div class="col-span-2 rounded-lg border border-dashed border-border px-3 py-2.5">
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted">Reversal Atas</dt>
                            <dd class="mt-1">
                                <a href="{{ route('savings.withdrawals.show', $withdrawal->reversalOf) }}"
                                    wire:navigate class="font-mono text-sm font-medium text-primary hover:underline">
                                    {{ $withdrawal->reversalOf->withdrawal_number }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    <div class="col-span-2">
                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted">
                            <x-ui.icon name="document" class="h-3.5 w-3.5" /> Catatan
                        </dt>
                        <dd class="mt-1 text-sm text-text">{{ $withdrawal->notes ?: '—' }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>

        {{-- KANAN: workflow stepper + audit --}}
        <div class="space-y-6">
            {{-- Workflow stepper --}}
            <x-ui.card>
                <h3 class="text-sm font-semibold text-text">Alur Pencairan</h3>

                @if ($rejected)
                    <div
                        class="mt-4 flex items-start gap-2.5 rounded-xl bg-danger/5 px-3 py-2.5 text-xs text-danger ring-1 ring-inset ring-danger/15">
                        <x-ui.icon name="x" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>Pengajuan ini <span class="font-semibold">ditolak</span> dan bersifat final. Saldo tidak
                            berubah.</span>
                    </div>
                @endif

                <ol class="mt-4 space-y-0">
                    @foreach ($steps as $i => $step)
                        @php($n = $node[$step['state']])
                        <li class="relative flex gap-3 pb-6 last:pb-0">
                            {{-- Garis penghubung --}}
                            @unless ($loop->last)
                                <span
                                    class="absolute left-3.75 top-8 h-[calc(100%-1.5rem)] w-0.5 {{ $n['line'] }}"></span>
                            @endunless
                            {{-- Node --}}
                            <span
                                class="relative z-10 grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $n['ring'] }} {{ $step['state'] === 'current' ? 'ring-4 ring-primary/15' : '' }}">
                                @if ($step['state'] === 'done')
                                    <x-ui.icon name="check" class="h-4 w-4" />
                                @else
                                    <x-ui.icon name="{{ $step['icon'] }}" class="h-4 w-4" />
                                @endif
                            </span>
                            {{-- Konten --}}
                            <div class="min-w-0 flex-1 pt-1">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-semibold {{ $n['label'] }}">{{ $step['label'] }}</p>
                                    @if ($step['state'] === 'current')
                                        <span
                                            class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">Menunggu</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-muted">{{ $step['desc'] }}</p>
                                @if ($step['time'])
                                    <p class="mt-1 text-[11px] text-muted">
                                        {{ $step['time']->translatedFormat('d M Y · H.i') }}
                                        WIB{{ $step['who'] ? ' · ' . $step['who'] : '' }}
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>

            {{-- Audit Trail --}}
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

    {{-- Modal: Konfirmasi transisi --}}
    @php($meta = $this->confirmMeta())
    <div x-data="{ show: @entangle('showConfirm').live }" x-show="show" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95" @keydown.escape.window="show = false" role="dialog"
            aria-modal="true"
            class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
            <div class="flex items-start gap-3">
                <span @class([
                    'mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full',
                    'bg-danger/10 text-danger' => $meta['variant'] === 'danger',
                    'bg-primary/10 text-primary' => $meta['variant'] !== 'danger',
                ])>
                    <x-ui.icon :name="$meta['icon'] ?: 'check'" class="h-5 w-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-text">{{ $meta['title'] ?: 'Konfirmasi' }}
                    </h3>
                    <p class="mt-1 text-xs text-muted">{{ $meta['desc'] }}</p>
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-3">
                <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                <x-ui.button type="button" :variant="$meta['variant']" wire:click="performConfirm"
                    wire:loading.attr="disabled" wire:target="performConfirm">
                    <svg wire:loading wire:target="performConfirm" class="h-4 w-4 animate-spin" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    {{ $meta['cta'] ?: 'Lanjutkan' }}
                </x-ui.button>
            </div>
        </div>
    </div>

    {{-- Modal: Reversal --}}
    <div x-data="{ show: @entangle('showReverse').live }" x-show="show" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="show" x-transition.opacity class="absolute inset-0 bg-black/40" @click="show = false"></div>
        <div x-show="show" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95" @keydown.escape.window="show = false" role="dialog"
            aria-modal="true"
            class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-danger/10 text-danger">
                    <x-ui.icon name="arrow-uturn-left" class="h-5 w-5" />
                </span>
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-text">Reversal Pencairan</h3>
                    <p class="mt-1 text-xs text-muted">Membuat transaksi-lawan; saldo simpanan tersesuaikan. Baris asli
                        tidak dihapus.</p>
                </div>
            </div>

            <form wire:submit="performReverse" class="mt-5 space-y-4">
                <div class="space-y-1">
                    <label for="reverseReason" class="block text-sm font-medium text-text">Alasan Reversal</label>
                    <textarea id="reverseReason" wire:model="reverseReason" rows="3"
                        placeholder="Wajib, minimal 5 karakter. Akan tercatat di log audit." @class([
                            'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-text placeholder:text-muted transition focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                            'border-border' => !$errors->has('reverseReason'),
                            'border-danger focus-visible:ring-danger' => $errors->has('reverseReason'),
                        ])></textarea>
                    @error('reverseReason')
                        <p class="text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <x-ui.button type="button" variant="ghost" @click="show = false">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled"
                        wire:target="performReverse">
                        <svg wire:loading wire:target="performReverse" class="h-4 w-4 animate-spin" fill="none"
                            viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z">
                            </path>
                        </svg>
                        Proses Reversal
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>

    <x-ui.toast-host />
</div>
