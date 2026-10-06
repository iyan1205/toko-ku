<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserService
{
    /** @param array{name:string,email:string,phone?:?string,password:string,role:string,is_active?:bool} $data */
    public function buat(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->syncRoles($data['role']);

            return $user;
        });
    }

    /** @param array{name:string,email:string,phone?:?string,password?:?string,role:string,is_active?:bool} $data */
    public function perbarui(User $user, array $data, User $aktor): User
    {
        $aktif = $data['is_active'] ?? $user->is_active;
        $peranBerubah = ! $user->hasRole($data['role']);
        $dinonaktifkan = $user->is_active && ! $aktif;

        if ($user->is($aktor) && ($peranBerubah || $dinonaktifkan)) {
            throw new AturanBisnisException('Anda tidak dapat mengubah peran atau menonaktifkan akun Anda sendiri.');
        }

        if ($user->isSuperAdmin() && ($data['role'] !== RoleName::SuperAdmin->value || $dinonaktifkan)) {
            $this->pastikanMasihAdaSuperAdminLain($user);
        }

        return DB::transaction(function () use ($user, $data, $aktif) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'is_active' => $aktif,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();
            $user->syncRoles($data['role']);

            return $user;
        });
    }

    public function ubahStatus(User $user, User $aktor): User
    {
        if ($user->is($aktor)) {
            throw new AturanBisnisException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        if ($user->is_active && $user->isSuperAdmin()) {
            $this->pastikanMasihAdaSuperAdminLain($user);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return $user;
    }

    /** Soft delete: baris tetap ada agar riwayat transaksi yang merujuk pengguna ini tidak rusak. */
    public function hapus(User $user, User $aktor): void
    {
        if ($user->is($aktor)) {
            throw new AturanBisnisException('Anda tidak dapat menghapus akun Anda sendiri.');
        }

        if ($user->isSuperAdmin()) {
            $this->pastikanMasihAdaSuperAdminLain($user);
        }

        $user->delete();
    }

    private function pastikanMasihAdaSuperAdminLain(User $user): void
    {
        $adaLain = User::role(RoleName::SuperAdmin->value)
            ->aktif()
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $adaLain) {
            throw new AturanBisnisException('Minimal harus ada satu Super Admin aktif.');
        }
    }
}
