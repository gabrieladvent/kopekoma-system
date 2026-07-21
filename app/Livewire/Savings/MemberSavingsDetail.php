<?php

namespace App\Livewire\Savings;

use App\Models\Member;
use App\Services\SavingsBalanceService;
use App\Services\SavingsMutationService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class MemberSavingsDetail extends Component
{
    public string $memberId;

    /** Filter jenis simpanan pada buku mutasi: all|pokok|wajib|sukarela|hari_raya|wajib_belanja */
    #[Url]
    public string $type = 'all';

    public function mount(Member $member): void
    {
        abort_unless(auth()->user()?->can('view_any_member::savings::balance') ?? false, 403);
        $this->memberId = $member->id;
    }

    public function render(): View
    {
        $member = Member::with(['agency:id,agency_name', 'grade:id,code,name'])->findOrFail($this->memberId);

        $balances = app(SavingsBalanceService::class);

        $all = $balances->allBalances($member);

        $holidayTotal = array_reduce(
            $all['hari_raya'],
            fn (string $carry, string $balance): string => bcadd($carry, $balance, 2),
            '0',
        );

        $ledger = app(SavingsMutationService::class)->ledgerFor($member);

        if ($this->type !== 'all') {
            $ledger = array_values(array_filter($ledger, fn (array $r): bool => $r['type'] === $this->type));
        }

        $totalMasuk = array_reduce($ledger, fn (string $c, array $r) => bcadd($c, $r['masuk'], 2), '0');

        $totalKeluar = array_reduce($ledger, fn (string $c, array $r) => bcadd($c, $r['keluar'], 2), '0');

        // Saldo akhir baris Total = Σ masuk − Σ keluar dari mutasi yang tampil.
        // Untuk filter "all" = total saldo anggota; saat difilter per jenis =
        // saldo bersih jenis tersebut.
        $totalSaldo = bcsub($totalMasuk, $totalKeluar, 2);

        return view('livewire.savings.member-savings-detail', [
            'member' => $member,
            'cards' => [
                ['label' => 'Pokok', 'value' => $all['pokok'], 'icon' => 'banknotes'],
                ['label' => 'Wajib', 'value' => $all['wajib'], 'icon' => 'banknotes'],
                ['label' => 'Sukarela', 'value' => $all['sukarela'], 'icon' => 'wallet'],
                ['label' => 'Hari Raya', 'value' => $holidayTotal, 'icon' => 'gift'],
                ['label' => 'Wajib Belanja', 'value' => $all['wajib_belanja'], 'icon' => 'shopping-cart'],
            ],
            'holidayByYear' => $all['hari_raya'],
            'total' => $balances->totalBalance($member),
            'ledger' => $ledger,
            'totalMasuk' => $totalMasuk,
            'totalKeluar' => $totalKeluar,
            'totalSaldo' => $totalSaldo,
        ])->layout('components.layouts.app', ['title' => 'Detail Saldo Anggota']);
    }
}
