<?php

namespace App\Policies;

use App\Models\User;

/**
 * Catatan: Super Admin melewati policy lewat Gate::before, sehingga aturan yang harus
 * berlaku untuk semua orang (tidak boleh hapus diri sendiri, minimal satu Super Admin)
 * dijaga di UserService, bukan di sini.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-users');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-users');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('manage-users') && ($user->isSuperAdmin() || ! $target->isSuperAdmin());
    }

    public function delete(User $user, User $target): bool
    {
        return $this->update($user, $target) && $user->isNot($target);
    }
}
