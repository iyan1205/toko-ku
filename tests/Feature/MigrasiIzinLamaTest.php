<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Migration 2026_11_08_142605: izin lama (manage-products, ...) menjadi {modul}.{fitur}.{aksi}
 * tanpa mengubah siapa boleh melakukan apa, dan tanpa menyentuh pengguna dan perannya.
 */
class MigrasiIzinLamaTest extends TestCase
{
    use RefreshDatabase;

    public function test_peran_dan_pengguna_mendapat_izin_pengganti_lalu_izin_lama_dihapus(): void
    {
        foreach (['manage-products', 'view-own-sales', 'manage-users'] as $nama) {
            Permission::create(['name' => $nama, 'guard_name' => 'web']);
        }
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'web'])->givePermissionTo('manage-products');
        $kasir = Role::create(['name' => 'Kasir', 'guard_name' => 'web'])->givePermissionTo('view-own-sales');
        $pegawai = User::factory()->create()->assignRole($kasir)->givePermissionTo('manage-users');

        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($admin->fresh()->hasPermissionTo('inventory.products.update'));
        $this->assertTrue($admin->fresh()->hasPermissionTo('inventory.recipes.delete'));
        $this->assertFalse($admin->fresh()->hasPermissionTo('pos.transactions.view-own'));
        $this->assertTrue($kasir->fresh()->hasPermissionTo('pos.transactions.view-own'));

        $pegawai = $pegawai->fresh();
        $this->assertTrue($pegawai->hasRole('Kasir'));
        $this->assertTrue($pegawai->hasDirectPermission('access.users.update'));

        $this->assertDatabaseMissing('permissions', ['name' => 'manage-products']);
        $this->assertDatabaseMissing('permissions', ['name' => 'manage-users']);
    }

    public function test_tidak_melakukan_apa_pun_di_database_tanpa_izin_lama(): void
    {
        $jumlah = Permission::count();

        $this->migration()->up();

        $this->assertSame($jumlah, Permission::count());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_08_142605_migrate_legacy_permissions_to_module_format.php');
    }
}
