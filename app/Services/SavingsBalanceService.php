<?php

namespace App\Services;

use App\Exceptions\UnsupportedSavingsType;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\ShoppingTransaction;
use Illuminate\Support\Collection;

class SavingsBalanceService
{
    private const SCALE = 2;

    private const DIRECT_TYPES = ['pokok', 'wajib', 'sukarela'];

    private const UNSUPPORTED_TYPES = ['swp', 'tabungan_berjangka'];

    public function balanceByType(Member $member, string $type): string
    {
        if (in_array($type, self::UNSUPPORTED_TYPES, true)) {
            throw UnsupportedSavingsType::forType($type);
        }

        if ($type === 'hari_raya') {
            throw new \InvalidArgumentException('Saldo hari_raya per-tahun: gunakan holidayBalance(member, year).');
        }

        if ($type === 'wajib_belanja') {
            return $this->shoppingBalance($member);
        }

        if (! in_array($type, self::DIRECT_TYPES, true)) {
            throw UnsupportedSavingsType::forType($type);
        }

        return bcsub(
            $this->depositNet($member, $type),
            $this->withdrawalNet($member, $type),
            self::SCALE
        );
    }

    public function holidayBalance(Member $member, int $year): string
    {
        $deposits = SavingsDeposit::query()
            ->where('member_id', $member->id)
            ->where('savings_type', 'hari_raya')
            ->whereYear('period_month', $year)
            ->signedAmount()
            ->value('net');

        $withdrawals = SavingsWithdrawal::query()
            ->where('member_id', $member->id)
            ->where('savings_type', 'hari_raya')
            ->where('status', 'cair')
            ->where('period_year', $year)
            ->signedAmount()
            ->value('net');

        return bcsub($this->toAmount($deposits), $this->toAmount($withdrawals), self::SCALE);
    }

    public function shoppingBalance(Member $member): string
    {
        $deposits = $this->depositNet($member, 'wajib_belanja');

        $usage = ShoppingTransaction::query()
            ->where('member_id', $member->id)
            ->signedAmount()
            ->value('net');

        return bcsub($deposits, $this->toAmount($usage), self::SCALE);
    }

    /**
     * Semua saldo anggota (D1 koreksi v5): deposits grouped, withdrawals cair grouped,
     * hari_raya per `period_year` terpisah, plus pemakaian belanja. Digabung di PHP.
     *
     * @return array{pokok:string,wajib:string,sukarela:string,wajib_belanja:string,hari_raya:array<int,string>}
     */
    public function allBalances(Member $member): array
    {
        $depositNet = SavingsDeposit::query()
            ->where('member_id', $member->id)
            ->whereIn('savings_type', ['pokok', 'wajib', 'sukarela', 'wajib_belanja'])
            ->groupBy('savings_type')
            ->selectRaw('savings_type, COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->pluck('net', 'savings_type');

        $withdrawalNet = SavingsWithdrawal::query()
            ->where('member_id', $member->id)
            ->where('status', 'cair')
            ->whereIn('savings_type', ['pokok', 'wajib', 'sukarela'])
            ->groupBy('savings_type')
            ->selectRaw('savings_type, COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->pluck('net', 'savings_type');

        $shoppingUsage = ShoppingTransaction::query()
            ->where('member_id', $member->id)
            ->signedAmount()
            ->value('net');

        $direct = [];
        foreach (self::DIRECT_TYPES as $type) {
            $direct[$type] = bcsub(
                $this->toAmount($depositNet->get($type)),
                $this->toAmount($withdrawalNet->get($type)),
                self::SCALE
            );
        }

        return [
            ...$direct,
            'wajib_belanja' => bcsub(
                $this->toAmount($depositNet->get('wajib_belanja')),
                $this->toAmount($shoppingUsage),
                self::SCALE
            ),
            'hari_raya' => $this->holidayBalancesByYear($member),
        ];
    }

    public function totalBalance(Member $member): string
    {
        $all = $this->allBalances($member);

        $total = bcadd(bcadd($all['pokok'], $all['wajib'], self::SCALE), $all['sukarela'], self::SCALE);
        $total = bcadd($total, $all['wajib_belanja'], self::SCALE);

        foreach ($all['hari_raya'] as $balance) {
            $total = bcadd($total, $balance, self::SCALE);
        }

        return $total;
    }

    /**
     * @return array<int, string> period_year => saldo
     */
    public function holidayBalancesByYear(Member $member): array
    {
        $deposits = SavingsDeposit::query()
            ->where('member_id', $member->id)
            ->where('savings_type', 'hari_raya')
            ->whereNotNull('period_month')
            ->get(['period_month', 'amount', 'is_reversal'])
            ->groupBy(fn ($row) => $row->period_month?->year)
            ->map(fn ($rows) => $this->sumSigned($rows));

        $withdrawals = SavingsWithdrawal::query()
            ->where('member_id', $member->id)
            ->where('savings_type', 'hari_raya')
            ->where('status', 'cair')
            ->whereNotNull('period_year')
            ->get(['period_year', 'amount', 'is_reversal'])
            ->groupBy('period_year')
            ->map(fn ($rows) => $this->sumSigned($rows));

        $years = $deposits->keys()->merge($withdrawals->keys())->unique();

        return $years
            ->mapWithKeys(fn ($year) => [
                (int) $year => bcsub(
                    $this->toAmount($deposits->get($year)),
                    $this->toAmount($withdrawals->get($year)),
                    self::SCALE
                ),
            ])
            ->all();
    }

    public function canWithdraw(Member $member, string $type, string $amount, ?int $year = null): bool
    {
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return false;
        }

        $balance = $type === 'hari_raya'
            ? $this->holidayBalance($member, $year ?? throw new \InvalidArgumentException('hari_raya butuh year'))
            : $this->balanceByType($member, $type);

        return bccomp($amount, $balance, self::SCALE) <= 0;
    }

    public function canSpendShopping(Member $member, string $amount): bool
    {
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return false;
        }

        return bccomp($amount, $this->shoppingBalance($member), self::SCALE) <= 0;
    }

    private function depositNet(Member $member, string $type): string
    {
        $net = SavingsDeposit::query()
            ->where('member_id', $member->id)
            ->where('savings_type', $type)
            ->signedAmount()
            ->value('net');

        return $this->toAmount($net);
    }

    private function withdrawalNet(Member $member, string $type): string
    {
        $net = SavingsWithdrawal::query()
            ->where('member_id', $member->id)
            ->where('savings_type', $type)
            ->where('status', 'cair')
            ->signedAmount()
            ->value('net');

        return $this->toAmount($net);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function sumSigned($rows): string
    {
        return $rows->reduce(
            fn (string $carry, $row) => bcadd(
                $carry,
                $row->is_reversal ? '-'.$row->amount : (string) $row->amount,
                self::SCALE
            ),
            '0'
        );
    }

    private function toAmount(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', self::SCALE);
    }
}
