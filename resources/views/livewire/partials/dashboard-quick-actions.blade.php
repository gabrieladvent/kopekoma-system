{{-- Aksi cepat dashboard. Tiap item digate per permission. --}}
<x-ui.card title="Aksi Cepat">
    <div class="space-y-2">
        @php($actions = [
            ['Setor Simpanan', 'Catat setoran anggota', 'plus', 'savings.deposits.create', 'create_savings::deposit', 'primary'],
            ['Bayar Angsuran', 'Catat pembayaran angsuran', 'credit-card', 'installments.create', 'create_installment', 'secondary'],
            ['Pinjaman Baru', 'Catat akad pinjaman', 'receipt-percent', 'loans.create', 'create_loan', 'primary'],
            ['Pencairan', 'Ajukan pencairan simpanan', 'arrow-up-tray', 'savings.withdrawals.create', 'create_savings::withdrawal', 'warning'],
            ['Tambah Anggota', 'Daftarkan anggota baru', 'user', 'master.members.create', 'create_member', 'secondary'],
        ])
        @php($tones = [
            'primary' => 'bg-primary/10 text-primary',
            'secondary' => 'bg-secondary/10 text-secondary',
            'warning' => 'bg-warning/10 text-warning',
        ])
        @foreach ($actions as [$label, $desc, $icon, $route, $perm, $tone])
            @can($perm)
                <a href="{{ route($route) }}" wire:navigate
                   class="group flex items-center gap-3 rounded-xl border border-border p-3 transition hover:border-primary/40 hover:bg-primary/5">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl {{ $tones[$tone] ?? $tones['primary'] }} transition group-hover:scale-105">
                        <x-ui.icon :name="$icon" class="h-4.5 w-4.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $label }}</p>
                        <p class="truncate text-xs text-muted">{{ $desc }}</p>
                    </div>
                    <x-ui.icon name="chevron-right" class="ml-auto h-4 w-4 text-muted opacity-0 transition group-hover:opacity-100" />
                </a>
            @endcan
        @endforeach
    </div>
</x-ui.card>
