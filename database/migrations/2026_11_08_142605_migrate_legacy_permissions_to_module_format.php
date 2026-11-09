<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ganti izin lama (manage-products, create-sale, ...) ke format {modul}.{fitur}.{aksi}.
 *
 * Setiap peran/pengguna yang memegang izin lama mendapat SEMUA izin baru penggantinya,
 * lalu izin lama dihapus. Hak akses yang berlaku tidak berubah; data pengguna dan perannya
 * tidak disentuh. Pemetaan ditulis tetap di sini (bukan dari config) agar migration ini
 * selalu menghasilkan hal yang sama walau config berubah di kemudian hari.
 *
 * Catatan: view-recipes tidak dipetakan. Izin itu tidak pernah berlaku (semua route resep
 * mensyaratkan manage-products), dan resep memuat biaya bahan yang memang tidak untuk kasir.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    /** @var array<string, list<string>> izin lama => izin baru */
    private const MAP = [
        'view-dashboard' => ['dashboard.overview.view'],
        'manage-products' => [
            'inventory.products.view', 'inventory.products.create', 'inventory.products.update', 'inventory.products.delete',
            'inventory.products.import', 'inventory.products.export',
            'inventory.units.view', 'inventory.units.create', 'inventory.units.update', 'inventory.units.delete',
            'inventory.recipes.view', 'inventory.recipes.create', 'inventory.recipes.update', 'inventory.recipes.delete',
        ],
        'view-products' => ['inventory.products.view'],
        'manage-categories' => ['inventory.categories.view', 'inventory.categories.create', 'inventory.categories.update', 'inventory.categories.delete'],
        'manage-suppliers' => ['purchasing.suppliers.view', 'purchasing.suppliers.create', 'purchasing.suppliers.update', 'purchasing.suppliers.delete'],
        'manage-customers' => ['pos.customers.view', 'pos.customers.create', 'pos.customers.update', 'pos.customers.delete'],
        'create-sale' => ['pos.transactions.create'],
        'view-sales' => ['pos.transactions.view'],
        'view-own-sales' => ['pos.transactions.view-own'],
        'void-sale' => ['pos.transactions.void'],
        'process-returns' => ['pos.returns.create'],
        'print-receipt' => ['pos.transactions.print'],
        'create-purchase' => ['purchasing.invoices.create'],
        'view-purchases' => ['purchasing.invoices.view'],
        'void-purchase' => ['purchasing.invoices.void'],
        'manage-stock' => ['inventory.stock.view', 'inventory.stock-card.view', 'inventory.adjustments.create'],
        'view-stock' => ['inventory.stock.view'],
        'stock-opname' => ['inventory.opnames.view', 'inventory.opnames.create'],
        'manage-payables' => ['purchasing.payables.view', 'purchasing.payables.create'],
        'manage-receivables' => ['pos.receivables.view', 'pos.receivables.create'],
        'view-reports' => ['reports.sales.view', 'reports.sales.export'],
        'view-recipes' => [],
        'record-attendance' => ['sdm.attendances.record'],
        'manage-attendance' => ['sdm.attendances.view', 'sdm.attendances.create', 'sdm.attendances.update', 'sdm.attendances.delete'],
        'view-schedules' => ['sdm.schedules.view'],
        'manage-schedules' => [
            'sdm.schedules.view', 'sdm.schedules.update',
            'sdm.shifts.view', 'sdm.shifts.create', 'sdm.shifts.update', 'sdm.shifts.delete',
        ],
        'request-leave' => ['sdm.leaves.create'],
        'view-leaves' => ['sdm.leaves.view'],
        'manage-leave-types' => ['sdm.leave-types.view', 'sdm.leave-types.create', 'sdm.leave-types.update', 'sdm.leave-types.delete'],
        'manage-users' => ['access.users.view', 'access.users.create', 'access.users.update', 'access.users.delete'],
        'manage-settings' => ['settings.store.view', 'settings.store.update'],
    ];

    public function up(): void
    {
        $this->convert(self::MAP);
    }

    /** Kembali ke izin lama: peran/pengguna mendapat izin lama bila memegang semua izin penggantinya. */
    public function down(): void
    {
        $tables = config('permission.table_names');
        $pivot = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $permissionPivot = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $morphKey = config('permission.column_names.model_morph_key');

        DB::transaction(function () use ($tables, $pivot, $permissionPivot, $morphKey) {
            foreach (self::MAP as $oldName => $newNames) {
                $oldId = $this->permissionId($oldName);
                $newIds = DB::table($tables['permissions'])->where('guard_name', self::GUARD)->whereIn('name', $newNames)->pluck('id');

                if ($newNames === [] || $newIds->count() !== count($newNames)) {
                    continue;
                }

                $roleIds = DB::table($tables['role_has_permissions'])->whereIn($permissionPivot, $newIds)
                    ->select($pivot)->groupBy($pivot)->havingRaw('COUNT(*) = ?', [$newIds->count()])->pluck($pivot);

                foreach ($roleIds as $roleId) {
                    DB::table($tables['role_has_permissions'])->insertOrIgnore([$permissionPivot => $oldId, $pivot => $roleId]);
                }

                $models = DB::table($tables['model_has_permissions'])->whereIn($permissionPivot, $newIds)
                    ->select('model_type', $morphKey)->groupBy('model_type', $morphKey)
                    ->havingRaw('COUNT(*) = ?', [$newIds->count()])->get();

                foreach ($models as $model) {
                    DB::table($tables['model_has_permissions'])->insertOrIgnore([
                        $permissionPivot => $oldId, 'model_type' => $model->model_type, $morphKey => $model->{$morphKey},
                    ]);
                }
            }

            $newNames = collect(self::MAP)->flatten()->unique()->values()->all();
            DB::table($tables['permissions'])->where('guard_name', self::GUARD)->whereIn('name', $newNames)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<string, list<string>> $map */
    private function convert(array $map): void
    {
        $tables = config('permission.table_names');
        $pivot = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $permissionPivot = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $morphKey = config('permission.column_names.model_morph_key');

        $old = DB::table($tables['permissions'])->where('guard_name', self::GUARD)->whereIn('name', array_keys($map))->get();

        if ($old->isEmpty()) {
            return; // Database baru: izin dibuat oleh RolePermissionSeeder.
        }

        DB::transaction(function () use ($old, $map, $tables, $pivot, $permissionPivot, $morphKey) {
            foreach ($old as $oldPermission) {
                $newIds = array_map(fn (string $name) => $this->permissionId($name), $map[$oldPermission->name]);

                $roleIds = DB::table($tables['role_has_permissions'])->where($permissionPivot, $oldPermission->id)->pluck($pivot);
                foreach ($roleIds as $roleId) {
                    foreach ($newIds as $newId) {
                        DB::table($tables['role_has_permissions'])->insertOrIgnore([$permissionPivot => $newId, $pivot => $roleId]);
                    }
                }

                $models = DB::table($tables['model_has_permissions'])->where($permissionPivot, $oldPermission->id)->get();
                foreach ($models as $model) {
                    foreach ($newIds as $newId) {
                        DB::table($tables['model_has_permissions'])->insertOrIgnore([
                            $permissionPivot => $newId, 'model_type' => $model->model_type, $morphKey => $model->{$morphKey},
                        ]);
                    }
                }
            }

            $oldIds = $old->pluck('id');
            DB::table($tables['role_has_permissions'])->whereIn($permissionPivot, $oldIds)->delete();
            DB::table($tables['model_has_permissions'])->whereIn($permissionPivot, $oldIds)->delete();
            DB::table($tables['permissions'])->whereIn('id', $oldIds)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Id izin, dibuat bila belum ada. */
    private function permissionId(string $name): int
    {
        $table = config('permission.table_names.permissions');
        $id = DB::table($table)->where('guard_name', self::GUARD)->where('name', $name)->value('id');

        return $id ?? DB::table($table)->insertGetId([
            'name' => $name, 'guard_name' => self::GUARD, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
