<?php

namespace App\Policies;

use App\Models\ShoppingTransaction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShoppingTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_shopping::transaction');
    }

    public function view(User $user, ShoppingTransaction $shoppingTransaction): bool
    {
        return $user->can('view_shopping::transaction');
    }

    public function create(User $user): bool
    {
        return $user->can('create_shopping::transaction');
    }

    /**
     * Pemakaian belanja bersifat immutable — koreksi lewat reversal (D3/D6),
     * bukan edit/hapus.
     */
    public function update(User $user, ShoppingTransaction $shoppingTransaction): bool
    {
        return false;
    }

    public function delete(User $user, ShoppingTransaction $shoppingTransaction): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ShoppingTransaction $shoppingTransaction): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ShoppingTransaction $shoppingTransaction): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, ShoppingTransaction $shoppingTransaction): bool
    {
        return false;
    }

    /**
     * Reversal pemakaian belanja = Petugas + Pengurus uniform (D7).
     */
    public function reverse(User $user, ShoppingTransaction $shoppingTransaction): bool
    {
        return $user->can('reverse_shopping::transaction');
    }
}
