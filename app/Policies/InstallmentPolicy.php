<?php

namespace App\Policies;

use App\Models\Installment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InstallmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_installment');
    }

    public function view(User $user, Installment $installment): bool
    {
        return $user->can('view_installment');
    }

    public function create(User $user): bool
    {
        return $user->can('create_installment');
    }

    public function update(User $user, Installment $installment): bool
    {
        return false;
    }

    public function delete(User $user, Installment $installment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Installment $installment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Installment $installment): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Installment $installment): bool
    {
        return false;
    }

    public function reverse(User $user, Installment $installment): bool
    {
        // Angsuran dari saldo simpanan: reverse MENAIKKAN saldo sukarela (withdrawable)
        // anggota — setara privilege pelunasan. Gate `reverse_loan` (Pengurus), cegah
        // privilege inversion (Petugas "manufaktur" saldo). ADR 2026-07-22 item 1e.
        if ($installment->is_settlement || $installment->payment_method === 'saldo_simpanan') {
            return $user->can('reverse_loan');
        }

        return $user->can('reverse_installment');
    }

    public function settleEarly(User $user): bool
    {
        return $user->can('settle_early_installment');
    }

    /**
     * Debit angsuran dari saldo Simpanan Sukarela (ADR 2026-07-22) — Pengurus-only.
     * Sukarela = uang withdrawable anggota → otoritas setara pencairan + atribusi.
     */
    public function payFromSavings(User $user): bool
    {
        return $user->can('pay_installment_from_savings');
    }
}
