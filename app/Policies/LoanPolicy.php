<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LoanPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_loan');
    }

    public function view(User $user, Loan $loan): bool
    {
        return $user->can('view_loan');
    }

    public function create(User $user): bool
    {
        return $user->can('create_loan');
    }

    /**
     * Pinjaman immutable — koreksi salah-input lewat reversal record (D3, item 2d),
     * bukan edit/hapus.
     */
    public function update(User $user, Loan $loan): bool
    {
        return false;
    }

    public function delete(User $user, Loan $loan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Loan $loan): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Loan $loan): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Loan $loan): bool
    {
        return false;
    }

    /**
     * Ability custom (D7): koreksi salah-input pinjaman = reversal seluruh record,
     * hanya Pengurus+ (lihat seeder). Dipakai aksi koreksi (item 2d).
     */
    public function reverse(User $user, Loan $loan): bool
    {
        return $user->can('reverse_loan');
    }
}
