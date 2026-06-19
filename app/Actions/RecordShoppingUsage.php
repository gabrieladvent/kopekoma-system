<?php

namespace App\Actions;

use App\Exceptions\CannotSpendShopping;
use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Illuminate\Support\Facades\DB;

/**
 * Engine pemakaian saldo Wajib Belanja — dipakai bersama jalur manual (Filament 5a)
 * dan jalur store_api (controller API). ADR Integrasi API Toko, D4.
 *
 * Invariant anti over-spend: lock baris member → re-cek saldo otoritatif di dalam
 * lock → create. Tanpa lock, dua pemakaian konkuren bisa menembus saldo
 * (tak ada unique-constraint agregat yang menjaga "Σ pakai ≤ saldo").
 *
 * Idempotency (UniqueConstraintViolationException dari `idempotency_key`) sengaja
 * TIDAK ditangani di sini — dibiarkan menjalar ke pemanggil, karena respons
 * idempotensi berbeda per jalur (Filament Halt+notif vs API 200/409 ownership-check).
 */
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
