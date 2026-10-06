<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

trait SiapkanPeran
{
    protected function siapkanPeran(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function buatPengguna(RoleName $peran, array $atribut = []): User
    {
        return User::factory()->create($atribut)->assignRole($peran->value);
    }
}
