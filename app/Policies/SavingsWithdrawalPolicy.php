<?php

namespace App\Policies;

use App\Models\SavingsWithdrawal;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SavingsWithdrawalPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_savings::withdrawal');
    }

    public function view(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return $user->can('view_savings::withdrawal');
    }

    /**
     * Create = membuat pencairan berstatus `draft` (belum keluar uang) → Petugas+
     * (D7/D10). ACC & Cair-nya yang dibatasi Pengurus+ lewat ability custom.
     */
    public function create(User $user): bool
    {
        return $user->can('create_savings::withdrawal');
    }

    /**
     * Edit hanya saat `draft` (D10) — begitu masuk acc/cair/ditolak, dokumen
     * terkunci agar tak ada bypass gate uang-keluar lewat edit.
     */
    public function update(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return $savingsWithdrawal->status === 'draft'
            && $user->can('update_savings::withdrawal');
    }

    public function delete(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return false;
    }

    /**
     * ACC pencairan (`draft → acc`) — mata kedua sebelum uang keluar (D8-A).
     * Default assignment: Pengurus + Super Admin. Reject memakai ability yang
     * sama (otoritas keputusan yang sama).
     */
    public function approve(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return $user->can('approve_savings::withdrawal');
    }

    /**
     * Cairkan pencairan (`acc → cair`) — titik uang-keluar (D10). Pengurus+.
     */
    public function disburse(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return $user->can('disburse_savings::withdrawal');
    }

    /**
     * Reversal pencairan `cair` — uniform Petugas+ (D7, dikonfirmasi pengurus),
     * tradeoff dikontrol via laporan reversal periodik.
     */
    public function reverse(User $user, SavingsWithdrawal $savingsWithdrawal): bool
    {
        return $user->can('reverse_savings::withdrawal');
    }
}
