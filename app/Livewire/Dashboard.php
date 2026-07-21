<?php

namespace App\Livewire;

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $canLoan = $user?->can('view_any_loan') ?? false;

        $finance = $canFinance ? $this->financeMetrics() : null;
        $members = $canMembers ? $this->memberMetrics() : null;
        $loans = $canLoan ? $this->loanMetrics() : null;
        $recent = $canFinance ? $this->recentDeposits() : collect();

        return view('livewire.dashboard', [
            'canFinance' => $canFinance,
            'canMembers' => $canMembers,
            'canLoan' => $canLoan,
            'finance' => $finance,
            'members' => $members,
            'loans' => $loans,
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
        $withdrawnNet = (string) (SavingsWithdrawal::query()->where('status', WithdrawalStatus::Cair)->signedAmount()->value('net') ?? '0');
        $totalBalance = bcsub($depositNet, $withdrawnNet, 2);

        $thisMonth = (string) (SavingsDeposit::query()
            ->whereBetween('deposit_date', [$monthStart->toDateString(), $now->copy()->endOfMonth()->toDateString()])
            ->signedAmount()->value('net') ?? '0');

        $lastMonth = (string) (SavingsDeposit::query()
            ->whereBetween('deposit_date', [$lastMonthStart->toDateString(), $lastMonthStart->copy()->endOfMonth()->toDateString()])
            ->signedAmount()->value('net') ?? '0');

        $pendingWithdrawals = SavingsWithdrawal::query()->whereIn('status', [WithdrawalStatus::Draft, WithdrawalStatus::Acc])->count();

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
     * Agregat pinjaman — outstanding, status, tunggakan & jatuh tempo dekat.
     *
     * @return array<string, mixed>
     */
    private function loanMetrics(): array
    {
        $today = Carbon::today();

        $active = Loan::query()->where('status', LoanStatus::Cair)->count();
        $settled = Loan::query()->where('status', LoanStatus::Lunas)->count();

        // Sisa pokok berjalan = Σ principal pinjaman aktif − Σ pokok terbayar (net reversal).
        $principalActive = (string) (Loan::query()->where('status', LoanStatus::Cair)->sum('principal_amount') ?? '0');
        $paidNet = (string) (DB::table('installments')
            ->join('loans', 'loans.id', '=', 'installments.loan_id')
            ->where('loans.status', LoanStatus::Cair)
            ->selectRaw('COALESCE(SUM((CASE WHEN installments.is_reversal = 0 THEN 1 ELSE -1 END) * loans.monthly_principal), 0) as net')
            ->value('net') ?? '0');

        $outstanding = bcsub(bcadd($principalActive, '0', 2), bcadd($paidNet, '0', 2), 2);
        if (bccomp($outstanding, '0', 2) < 0) {
            $outstanding = '0.00';
        }

        $overdue = InstallmentSchedule::query()->overdue()
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Cair))
            ->count();

        $dueSoon = InstallmentSchedule::query()
            ->where('status', InstallmentScheduleStatus::BelumBayar)
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $today->copy()->addDays(7)->toDateString())
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Cair))
            ->count();

        $disbursedThisMonth = (string) (Loan::query()
            ->whereBetween('disbursement_date', [
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString(),
            ])
            ->sum('disbursed_amount') ?? '0');

        return [
            'active' => $active,
            'settled' => $settled,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'due_soon' => $dueSoon,
            'disbursed_this_month' => $disbursedThisMonth,
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
