<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aman dijalankan berulang kali (idempotent): izin dan peran dibuat bila belum ada,
 * lalu izin tiap peran disinkronkan dengan matriks di bawah.
 */
class RolePermissionSeeder extends Seeder
{
    /** Seluruh izin aplikasi. Izin baru pada tahap berikutnya cukup ditambahkan di sini. */
    public const IZIN = [
        'view-dashboard',
        'manage-products',
        'view-products',
        'manage-categories',
        'manage-suppliers',
        'manage-customers',
        'create-sale',
        'view-sales',
        'view-own-sales',
        'void-sale',
        'process-returns',
        'print-receipt',
        'create-purchase',
        'view-purchases',
        'void-purchase',
        'manage-stock',
        'view-stock',
        'stock-opname',
        'manage-payables',
        'manage-receivables',
        'view-reports',
        'manage-users',
        'manage-settings',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::IZIN as $nama) {
            Permission::findOrCreate($nama, 'web');
        }

        // Super Admin: semua izin (ditambah Gate::before di AppServiceProvider).
        Role::findOrCreate(RoleName::SuperAdmin->value, 'web')
            ->syncPermissions(Permission::all());

        Role::findOrCreate(RoleName::Admin->value, 'web')->syncPermissions([
            'view-dashboard',
            'manage-products', 'view-products',
            'manage-categories', 'manage-suppliers', 'manage-customers',
            'create-sale', 'view-sales', 'void-sale', 'process-returns', 'print-receipt',
            'create-purchase', 'view-purchases', 'void-purchase',
            'manage-stock', 'view-stock', 'stock-opname',
            'manage-payables', 'manage-receivables',
            'view-reports',
        ]);

        // Kasir tidak punya izin mengubah produk/harga beli dan tidak bisa void transaksi.
        Role::findOrCreate(RoleName::Kasir->value, 'web')->syncPermissions([
            'create-sale',
            'view-products',
            'view-stock',
            'print-receipt',
            'view-own-sales',
            'process-returns',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
