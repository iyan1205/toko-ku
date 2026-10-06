<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->buat('Super Admin', 'admin@example.com', RoleName::SuperAdmin);

        // Akun contoh untuk mencoba hak akses; tidak dibuat di production.
        if (! app()->isProduction()) {
            $this->buat('Admin Toko', 'manajer@example.com', RoleName::Admin);
            $this->buat('Kasir Toko', 'kasir@example.com', RoleName::Kasir);
        }
    }

    private function buat(string $nama, string $email, RoleName $peran): void
    {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->fill(['name' => $nama, 'password' => 'password', 'is_active' => true])->save();
        }

        $user->syncRoles($peran->value);
    }
}
