<?php

namespace App\Services\Access;

use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\PersetujuanCuti;
use App\Models\TahapPersetujuan;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Peran dan hak aksesnya. Aturan di sini berlaku untuk semua orang, termasuk Super Admin
 * (yang melewati policy lewat Gate::before):
 *   - peran Super Admin tidak bisa diubah, dihapus, atau dikurangi izinnya;
 *   - nama peran bawaan tidak bisa diganti (seeder mengenalinya dari nama);
 *   - peran yang masih dipakai pengguna atau alur persetujuan cuti tidak bisa dihapus;
 *   - selain Super Admin, pengguna hanya bisa memberi izin yang ia pegang sendiri.
 */
class RoleService
{
    private const GUARD = 'web';

    /** @param array{name: string, description?: ?string} $data */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create(['name' => trim($data['name']), 'guard_name' => self::GUARD]);
            $role->forceFill(['description' => $data['description'] ?? null])->save();

            activity('hak-akses')->performedOn($role)->event('created')
                ->log("Peran \"{$role->name}\" ditambahkan");

            return $role;
        });
    }

    /**
     * Ganti nama peran buatan sendiri ikut memperbarui alur persetujuan cuti yang menyimpan nama peran.
     *
     * @param  array{name: string, description?: ?string}  $data
     */
    public function update(Role $role, array $data): Role
    {
        $this->guardNotSuperAdmin($role, 'diubah');

        $oldName = $role->name;
        $newName = trim($data['name']);

        if ($newName !== $oldName && $this->isBuiltIn($role)) {
            throw new AturanBisnisException('Nama peran bawaan tidak dapat diganti. Keterangan dan hak aksesnya tetap bisa diubah.');
        }

        return DB::transaction(function () use ($role, $oldName, $newName, $data) {
            $role->forceFill(['name' => $newName, 'description' => $data['description'] ?? null])->save();

            if ($newName !== $oldName) {
                TahapPersetujuan::query()->where('peran', $oldName)->update(['peran' => $newName]);
                PersetujuanCuti::query()->where('peran', $oldName)->update(['peran' => $newName]);

                activity('hak-akses')->performedOn($role)->event('updated')
                    ->withProperties(['old' => ['nama' => $oldName], 'attributes' => ['nama' => $newName]])
                    ->log("Peran \"{$oldName}\" diganti nama menjadi \"{$newName}\"");
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        $this->guardNotSuperAdmin($role, 'dihapus');

        if ($this->isBuiltIn($role)) {
            throw new AturanBisnisException('Peran bawaan tidak dapat dihapus.');
        }

        $userCount = $this->userCount($role);
        if ($userCount > 0) {
            throw new AturanBisnisException("Peran \"{$role->name}\" masih dipakai {$userCount} pengguna. Pindahkan pengguna tersebut ke peran lain dulu.");
        }

        if (TahapPersetujuan::query()->where('peran', $role->name)->exists()) {
            throw new AturanBisnisException("Peran \"{$role->name}\" masih dipakai di alur persetujuan jenis cuti. Ubah alurnya dulu.");
        }

        DB::transaction(function () use ($role) {
            activity('hak-akses')->event('deleted')
                ->withProperties(['nama' => $role->name])
                ->log("Peran \"{$role->name}\" dihapus");

            $role->delete();
        });
    }

    /**
     * Ganti seluruh hak akses peran dengan daftar baru.
     *
     * @param  list<string>  $permissions
     * @return array{added: list<string>, removed: list<string>}
     */
    public function syncPermissions(Role $role, array $permissions, User $actor): array
    {
        $this->guardNotSuperAdmin($role, 'diubah hak aksesnya');

        $permissions = array_values(array_unique(array_intersect($permissions, PermissionCatalog::all())));
        $current = $role->permissions()->pluck('name')->all();
        $added = array_values(array_diff($permissions, $current));
        $removed = array_values(array_diff($current, $permissions));

        if (! $actor->isSuperAdmin()) {
            $notHeld = array_values(array_filter($added, fn (string $permission) => ! $actor->can($permission)));

            if ($notHeld !== []) {
                throw new AturanBisnisException('Anda hanya dapat memberikan hak akses yang Anda miliki sendiri. Tidak diizinkan: '
                    .implode(', ', array_map(PermissionCatalog::label(...), $notHeld)).'.');
            }
        }

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        DB::transaction(function () use ($role, $permissions, $added, $removed) {
            $role->syncPermissions($permissions);

            activity('hak-akses')->performedOn($role)->event('permissions_updated')
                ->withProperties(['ditambah' => $added, 'dicabut' => $removed])
                ->log(sprintf('Hak akses peran "%s" diubah: %d ditambah, %d dicabut', $role->name, count($added), count($removed)));
        });

        return ['added' => $added, 'removed' => $removed];
    }

    public function isSuperAdmin(Role $role): bool
    {
        return $role->name === RoleName::SuperAdmin->value;
    }

    public function isBuiltIn(Role $role): bool
    {
        return RoleName::tryFrom($role->name) !== null;
    }

    /** Jumlah pengguna (belum dihapus) yang memegang peran ini. */
    public function userCount(Role $role): int
    {
        return User::role($role)->count();
    }

    private function guardNotSuperAdmin(Role $role, string $action): void
    {
        if ($this->isSuperAdmin($role)) {
            throw new AturanBisnisException("Peran Super Admin adalah peran sistem dan tidak dapat {$action}.");
        }
    }
}
