<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Support\PermissionCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class HakAksesTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
    }

    public function test_seeder_membuat_delapan_peran_bawaan_dan_semua_izin_katalog(): void
    {
        $this->assertEqualsCanonicalizing(RoleName::values(), Role::pluck('name')->all());
        $this->assertEqualsCanonicalizing(PermissionCatalog::all(), Permission::pluck('name')->all());
    }

    public function test_seeder_dapat_dijalankan_berulang_tanpa_duplikat(): void
    {
        $jumlah = Permission::count();

        $this->siapkanPeran();

        $this->assertSame($jumlah, Permission::count());
        $this->assertSame(8, Role::count());
    }

    public function test_seeder_tidak_mengembalikan_hak_akses_yang_sudah_dicabut_lewat_matriks(): void
    {
        Role::findByName(RoleName::Kasir->value)->revokePermissionTo('inventory.stock.view');

        $this->siapkanPeran();

        $this->assertFalse(Role::findByName(RoleName::Kasir->value)->hasPermissionTo('inventory.stock.view'));
    }

    public function test_izin_baru_di_katalog_diberikan_ke_peran_bawaan_yang_sesuai(): void
    {
        config(['permission-matrix.modules.inventory.features.labels' => ['label' => 'Label harga', 'actions' => ['view', 'print']]]);

        $this->siapkanPeran();

        $this->assertTrue(Role::findByName(RoleName::Admin->value)->hasPermissionTo('inventory.labels.print'));
        $this->assertTrue(Role::findByName(RoleName::StafGudang->value)->hasPermissionTo('inventory.stock.view'));
        $this->assertFalse(Role::findByName(RoleName::Kasir->value)->hasPermissionTo('inventory.labels.print'));
    }

    public function test_setiap_pola_hak_akses_bawaan_cocok_dengan_izin_di_katalog(): void
    {
        foreach (RolePermissionSeeder::defaults() as $peran => $pola) {
            foreach ($pola as $satuPola) {
                $this->assertNotEmpty(PermissionCatalog::expand([$satuPola]), "Pola {$satuPola} untuk {$peran} tidak cocok dengan izin mana pun.");
            }
        }
    }

    public function test_kasir_hanya_punya_izin_operasional(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        foreach (['pos.transactions.create', 'inventory.products.view', 'inventory.stock.view', 'pos.transactions.print', 'pos.transactions.view-own'] as $izin) {
            $this->assertTrue($kasir->can($izin), "Kasir seharusnya boleh: $izin");
        }

        foreach (['inventory.products.update', 'pos.transactions.void', 'purchasing.invoices.create', 'reports.sales.view', 'access.users.view', 'settings.store.update', 'pos.transactions.view'] as $izin) {
            $this->assertFalse($kasir->can($izin), "Kasir seharusnya tidak boleh: $izin");
        }
    }

    public function test_admin_mengelola_toko_tetapi_bukan_pengguna_dan_pengaturan(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        foreach (['inventory.products.update', 'inventory.categories.create', 'purchasing.suppliers.update', 'pos.customers.update', 'pos.transactions.create', 'purchasing.invoices.create', 'inventory.adjustments.create', 'reports.sales.view', 'pos.transactions.void'] as $izin) {
            $this->assertTrue($admin->can($izin), "Admin seharusnya boleh: $izin");
        }

        $this->assertFalse($admin->can('access.users.view'));
        $this->assertFalse($admin->can('settings.store.update'));
    }

    public function test_super_admin_memiliki_akses_penuh(): void
    {
        $super = $this->buatPengguna(RoleName::SuperAdmin);

        $this->assertTrue($super->can('access.users.view'));
        $this->assertTrue($super->can('settings.store.update'));
        $this->assertTrue($super->can('izin-yang-belum-dibuat'));
    }

    public function test_halaman_pengguna_hanya_untuk_pemegang_izin_pengguna(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('users.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Admin))->get(route('users.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Pemilik))->get(route('users.index'))->assertOk();
        $this->actingAs($this->buatPengguna(RoleName::SuperAdmin))->get(route('users.index'))->assertOk();
    }

    public function test_menu_sidebar_mengikuti_hak_akses(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Pengguna');

        $this->actingAs($this->buatPengguna(RoleName::SuperAdmin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pengguna');
    }
}
