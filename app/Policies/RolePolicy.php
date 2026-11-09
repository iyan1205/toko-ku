<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Siapa boleh mengelola peran. Aturan yang berlaku untuk semua orang (Super Admin tidak bisa
 * diubah, peran bawaan tidak bisa dihapus, dst.) ada di RoleService.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access.roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('access.roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('access.roles.update') && ! $this->isSuperAdmin($role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('access.roles.delete') && RoleName::tryFrom($role->name) === null;
    }

    public function updatePermissions(User $user, Role $role): bool
    {
        return $user->can('access.permissions.update') && ! $this->isSuperAdmin($role);
    }

    private function isSuperAdmin(Role $role): bool
    {
        return $role->name === RoleName::SuperAdmin->value;
    }
}
