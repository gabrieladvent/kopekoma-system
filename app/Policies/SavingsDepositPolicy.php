<?php

namespace App\Policies;

use App\Models\SavingsDeposit;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SavingsDepositPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_savings::deposit');
    }

    public function view(User $user, SavingsDeposit $savingsDeposit): bool
    {
        return $user->can('view_savings::deposit');
    }

    public function create(User $user): bool
    {
        return $user->can('create_savings::deposit');
    }

    /**
     * Setoran finansial bersifat immutable — koreksi lewat reversal (D3),
     * bukan edit/hapus. Update & semua varian delete sengaja ditolak.
     */
    public function update(User $user, SavingsDeposit $savingsDeposit): bool
    {
        return false;
    }

    public function delete(User $user, SavingsDeposit $savingsDeposit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SavingsDeposit $savingsDeposit): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SavingsDeposit $savingsDeposit): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, SavingsDeposit $savingsDeposit): bool
    {
        return false;
    }

    /**
     * Ability custom (D7): reversal = Petugas + Pengurus, gating berbasis
     * permission Shield (bukan nama role hardcoded). Dipakai aksi reversal (2b).
     */
    public function reverse(User $user, SavingsDeposit $savingsDeposit): bool
    {
        return $user->can('reverse_savings::deposit');
    }
}
