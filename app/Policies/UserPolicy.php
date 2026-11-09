<?php

namespace App\Policies;

use App\Models\User;

/**
 * Super Admin melewati policy lewat Gate::before, sehingga aturan yang harus berlaku untuk
 * semua orang (tidak boleh hapus diri sendiri, minimal satu Super Admin aktif) dijaga di
 * UserService, bukan di sini.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access.users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('access.users.create');
    }

    /** Termasuk aktif/nonaktif dan reset kata sandi. */
    public function update(User $user, User $target): bool
    {
        return $user->can('access.users.update') && $this->mayManage($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can('access.users.delete') && $this->mayManage($user, $target) && $user->isNot($target);
    }

    /** Akun Super Admin hanya boleh diubah oleh Super Admin. */
    private function mayManage(User $user, User $target): bool
    {
        return $user->isSuperAdmin() || ! $target->isSuperAdmin();
    }
}
