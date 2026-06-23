<?php

namespace App\Livewire;

use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

class Dashboard extends Component
{
    private const SAVINGS_LABELS = [
        'pokok' => 'Pokok',
        'wajib' => 'Wajib',
        'hari_raya' => 'Hari Raya',
        'wajib_belanja' => 'Wajib Belanja',
        'sukarela' => 'Sukarela',
    ];

    public function render(): View
    {
        $user = auth()->user();
        $canFinance = $user?->can('view_any_savings::deposit') ?? false;
        $canMembers = $user?->can('view_any_member') ?? false;

        $finance = $canFinance ? $this->financeMetrics() : null;
        $members = $canMembers ? $this->memberMetrics() : null;
        $recent = $canFinance ? $this->recentDeposits() : collect();

        return view('livewire.dashboard', [
            'canFinance' => $canFinance,
            'canMembers' => $canMembers,
            'finance' => $finance,
            'members' => $members,
            'recent' => $recent,
            'greeting' => $this->greeting(),
        ])->layout('components.layouts.app', ['title' => 'Dashboard']);
    }

    /**
     * Agregat finansial — semua via SUM/COUNT ber-index, bukan iterasi per anggota.
     *
     * @return array<string, mixed>
     */
    private function financeMetrics(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();

        $depositNet = (string) (SavingsDeposit::query()->signedAmount()->value('net') ?? '0');
        $withdrawnNet = (string) (SavingsWithdrawal::query()->where('status', 'cair')->signedAmount()->value('net') ?? '0');
        $totalBalance = bcsub($depositNet, $withdrawnNet, 2);

        $thisMonth = (string) (SavingsDeposit::query()
            ->whereBetween('deposit_date', [$monthStart->toDateString(), $now->copy()->endOfMonth()->toDateString()])
            ->signedAmount()->value('net') ?? '0');

        $lastMonth = (string) (SavingsDeposit::query()
            ->whereBetween('deposit_date', [$lastMonthStart->toDateString(), $lastMonthStart->copy()->endOfMonth()->toDateString()])
            ->signedAmount()->value('net') ?? '0');

        $pendingWithdrawals = SavingsWithdrawal::query()->whereIn('status', ['draft', 'acc'])->count();

        $depositsCount = SavingsDeposit::query()->where('is_reversal', false)->count();
        $saversCount = SavingsDeposit::query()->where('is_reversal', false)->distinct()->count('member_id');

        $rawByType = SavingsDeposit::query()
            ->selectRaw('savings_type, COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->groupBy('savings_type')
            ->pluck('net', 'savings_type');

        $composition = [];

        foreach (self::SAVINGS_LABELS as $key => $label) {
            $value = (float) ($rawByType[$key] ?? 0);
            if ($value > 0) {
                $composition[] = ['key' => $key, 'label' => $label, 'value' => $value];
            }
        }

        $compositionTotal = array_sum(array_column($composition, 'value'));

        return [
            'total_balance' => $totalBalance,
            'this_month' => $thisMonth,
            'this_month_delta' => $this->percentDelta($thisMonth, $lastMonth),
            'pending_withdrawals' => $pendingWithdrawals,
            'deposits_count' => $depositsCount,
            'savers_count' => $saversCount,
            'composition' => $composition,
            'composition_total' => $compositionTotal,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function memberMetrics(): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        return [
            'active' => Member::query()->where('status', 'Aktif')->count(),
            'total' => Member::query()->count(),
            'new_this_month' => Member::query()->where('created_at', '>=', $monthStart)->count(),
        ];
    }

    private function recentDeposits()
    {
        return SavingsDeposit::query()
            ->with(['member:id,full_name'])
            ->latest('created_at')
            ->limit(4)
            ->get(['id', 'member_id', 'savings_type', 'amount', 'is_reversal', 'deposit_date', 'created_at']);
    }

    /**
     * Delta persen month-over-month; null bila bulan lalu nol (hindari /0).
     */
    private function percentDelta(string $current, string $previous): ?float
    {
        if (bccomp($previous, '0', 2) <= 0) {
            return null;
        }

        return round((((float) $current) - ((float) $previous)) / ((float) $previous) * 100, 1);
    }

    private function greeting(): string
    {
        $hour = (int) Carbon::now()->format('H');

        return match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 19 => 'Selamat sore',
            default => 'Selamat malam',
        };
    }

    /**
     * Label jenis simpanan untuk dipakai di view (recent transactions).
     */
    public function typeLabel(string $type): string
    {
        return self::SAVINGS_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
