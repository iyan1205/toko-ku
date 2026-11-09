<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin dari config/permission-matrix.php dan 8 peran bawaan beserta hak aksesnya.
 *
 * Aman dijalankan berulang kali dan TIDAK menimpa hak akses yang sudah diatur lewat
 * halaman Hak Akses:
 *   - izin yang belum ada dibuat;
 *   - peran bawaan yang belum ada dibuat dengan hak akses bawaannya;
 *   - peran bawaan yang sudah ada hanya mendapat izin yang BARU dibuat pada proses ini
 *     (sesuai daftar bawaannya). Izin lama yang sudah dicabut tidak dikembalikan;
 *   - Super Admin selalu memegang semua izin.
 */
class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    /** Izin yang dipakai semua pegawai untuk dirinya sendiri. */
    private const SELF_SERVICE = ['sdm.attendances.record', 'sdm.schedules.view', 'sdm.leaves.create'];

    /**
     * Hak akses bawaan per peran, berupa pola nama izin ("*" boleh dipakai).
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        return [
            RoleName::Pemilik->value => ['*'],

            RoleName::Admin->value => [
                'dashboard.*',
                'pos.transactions.view', 'pos.transactions.create', 'pos.transactions.void',
                'pos.transactions.print', 'pos.transactions.export',
                'pos.sessions.*', 'pos.returns.*', 'pos.customers.*', 'pos.receivables.*',
                'inventory.*',
                'purchasing.*',
                'reports.*',
                'sdm.employees.view', 'sdm.departments.view',
                'sdm.attendances.*', 'sdm.schedules.*', 'sdm.shifts.*', 'sdm.leaves.*',
            ],

            RoleName::Kasir->value => [
                'pos.transactions.view-own', 'pos.transactions.create', 'pos.transactions.print',
                'pos.sessions.view', 'pos.sessions.create',
                'pos.returns.view', 'pos.returns.create',
                'inventory.products.view', 'inventory.stock.view',
                ...self::SELF_SERVICE,
            ],

            RoleName::StafGudang->value => [
                'inventory.products.view', 'inventory.categories.view', 'inventory.units.view', 'inventory.recipes.view',
                'inventory.stock.*', 'inventory.stock-card.*', 'inventory.adjustments.*',
                'inventory.opnames.view', 'inventory.opnames.create',
                'inventory.transfers.view', 'inventory.transfers.create', 'inventory.warehouses.view',
                'purchasing.suppliers.view', 'purchasing.orders.view', 'purchasing.receipts.*',
                'reports.stock.*',
                ...self::SELF_SERVICE,
            ],

            RoleName::Purchasing->value => [
                'inventory.products.view', 'inventory.stock.view',
                'purchasing.suppliers.*',
                'purchasing.orders.view', 'purchasing.orders.create', 'purchasing.orders.update', 'purchasing.orders.print',
                'purchasing.invoices.view', 'purchasing.invoices.create',
                'purchasing.receipts.*',
                'purchasing.returns.view', 'purchasing.returns.create',
                'purchasing.payables.view',
                'reports.purchases.*',
                ...self::SELF_SERVICE,
            ],

            RoleName::Akuntan->value => [
                'dashboard.*',
                'accounting.*',
                'reports.*',
                'pos.transactions.view', 'pos.transactions.export', 'pos.receivables.*',
                'purchasing.invoices.view', 'purchasing.invoices.export', 'purchasing.payables.*',
                'sdm.payroll.view', 'sdm.payroll.export',
                ...self::SELF_SERVICE,
            ],

            RoleName::Hrd->value => [
                'sdm.*',
            ],
        ];
    }

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $tables = config('permission.table_names');
        $existing = Permission::query()->where('guard_name', self::GUARD)->pluck('name')->all();
        $created = array_values(array_diff(PermissionCatalog::all(), $existing));

        // Insert massal: jauh lebih cepat daripada Permission::create() satu per satu (173 izin).
        if ($created !== []) {
            $now = now();
            DB::table($tables['permissions'])->insert(array_map(
                fn (string $name) => ['name' => $name, 'guard_name' => self::GUARD, 'created_at' => $now, 'updated_at' => $now],
                $created,
            ));
        }

        $ids = Permission::query()->where('guard_name', self::GUARD)->pluck('id', 'name');

        $superAdmin = Role::findOrCreate(RoleName::SuperAdmin->value, self::GUARD);
        $this->describe($superAdmin, RoleName::SuperAdmin);
        $this->grant($superAdmin, $ids->values()->all());

        foreach (self::defaults() as $roleName => $patterns) {
            $defaults = PermissionCatalog::expand($patterns);
            $role = Role::query()->where('name', $roleName)->where('guard_name', self::GUARD)->first();

            if ($role === null) {
                $role = Role::create(['name' => $roleName, 'guard_name' => self::GUARD]);
                $this->grant($role, $ids->only($defaults)->values()->all());
            } else {
                $this->grant($role, $ids->only(array_intersect($defaults, $created))->values()->all());
            }

            $this->describe($role, RoleName::from($roleName));
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * Tambahkan izin (berdasarkan id) ke peran tanpa mencabut izin yang sudah ada.
     *
     * @param  list<int>  $permissionIds
     */
    private function grant(Role $role, array $permissionIds): void
    {
        if ($permissionIds === []) {
            return;
        }

        $table = config('permission.table_names.role_has_permissions');
        $rolePivot = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $permissionPivot = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        DB::table($table)->insertOrIgnore(array_map(
            fn (int $id) => [$permissionPivot => $id, $rolePivot => $role->getKey()],
            $permissionIds,
        ));
    }

    /** Isi keterangan peran bawaan bila masih kosong (keterangan yang sudah diubah dibiarkan). */
    private function describe(Role $role, RoleName $name): void
    {
        if (blank($role->getAttribute('description'))) {
            $role->forceFill(['description' => $name->deskripsi()])->save();
        }
    }
}
