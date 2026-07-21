<?php

namespace App\Policies;

use App\Models\MemberHolidaySaving;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MemberHolidaySavingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_member::holiday::saving');
    }

    public function view(User $user, MemberHolidaySaving $memberHolidaySaving): bool
    {
        return $user->can('view_member::holiday::saving');
    }

    public function create(User $user): bool
    {
        return $user->can('create_member::holiday::saving');
    }

    public function update(User $user, MemberHolidaySaving $memberHolidaySaving): bool
    {
        return $user->can('update_member::holiday::saving');
    }

    public function delete(User $user, MemberHolidaySaving $memberHolidaySaving): bool
    {
        return $user->can('delete_member::holiday::saving');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_member::holiday::saving');
    }

    public function forceDelete(User $user, MemberHolidaySaving $memberHolidaySaving): bool
    {
        return $user->can('force_delete_member::holiday::saving');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_member::holiday::saving');
    }

    public function restore(User $user, MemberHolidaySaving $memberHolidaySaving): bool
    {
        return $user->can('restore_member::holiday::saving');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_member::holiday::saving');
    }

    public function replicate(User $user, MemberHolidaySaving $memberHolidaySaving): bool
    {
        return $user->can('replicate_member::holiday::saving');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_member::holiday::saving');
    }
}
