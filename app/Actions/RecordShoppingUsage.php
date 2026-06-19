<?php

namespace App\Actions;

use App\Exceptions\CannotSpendShopping;
use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Illuminate\Support\Facades\DB;

class RecordShoppingUsage
{
    public function __construct(private readonly SavingsBalanceService $balances) {}

    /**
     * @param  array<string, mixed>  $attributes  atribut ShoppingTransaction (member_id, amount, source, dst.)
     *
     * @throws CannotSpendShopping saldo kurang / nominal tak valid
     */
    public function __invoke(array $attributes): ShoppingTransaction
    {
        $amount = (string) ($attributes['amount'] ?? '0');

        if (bccomp($amount, '0', 2) <= 0) {
            throw CannotSpendShopping::invalidAmount();
        }

        return DB::transaction(function () use ($attributes, $amount): ShoppingTransaction {
            /** @var Member $member */
            $member = Member::query()->lockForUpdate()->findOrFail($attributes['member_id']);

            if (! $this->balances->canSpendShopping($member, $amount)) {
                throw CannotSpendShopping::insufficientBalance($amount, $this->balances->shoppingBalance($member));
            }

            return ShoppingTransaction::create($attributes);
        });
    }
}
