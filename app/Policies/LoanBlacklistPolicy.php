<?php

namespace App\Policies;

use App\Models\LoanBlacklist;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LoanBlacklistPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_loan::blacklist');
    }

    public function view(User $user, LoanBlacklist $loanBlacklist): bool
    {
        return $user->can('view_loan::blacklist');
    }

    public function create(User $user): bool
    {
        return $user->can('create_loan::blacklist');
    }

    public function update(User $user, LoanBlacklist $loanBlacklist): bool
    {
        return $user->can('update_loan::blacklist');
    }

    public function delete(User $user, LoanBlacklist $loanBlacklist): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
