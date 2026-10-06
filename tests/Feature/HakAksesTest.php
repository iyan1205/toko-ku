<?php

namespace Tests\Feature;

use App\Enums\RoleName;
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

    public function test_seeder_membuat_tiga_peran_dan_semua_izin(): void
    {
        $this->assertEqualsCanonicalizing(RoleName::values(), Role::pluck('name')->all());
        $this->assertGreaterThanOrEqual(17, Permission::count());
    }

    public function test_seeder_dapat_dijalankan_berulang_tanpa_duplikat(): void
    {
        $jumlah = Permission::count();

        $this->siapkanPeran();

        $this->assertSame($jumlah, Permission::count());
        $this->assertSame(3, Role::count());
    }

    public function test_kasir_hanya_punya_izin_operasional(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        foreach (['create-sale', 'view-products', 'view-stock', 'print-receipt', 'view-own-sales'] as $izin) {
            $this->assertTrue($kasir->can($izin), "Kasir seharusnya boleh: $izin");
        }

        foreach (['manage-products', 'void-sale', 'create-purchase', 'view-reports', 'manage-users', 'manage-settings', 'view-sales'] as $izin) {
            $this->assertFalse($kasir->can($izin), "Kasir seharusnya tidak boleh: $izin");
        }
    }

    public function test_admin_mengelola_toko_tetapi_bukan_pengguna_dan_pengaturan(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        foreach (['manage-products', 'manage-categories', 'manage-suppliers', 'manage-customers', 'create-purchase', 'manage-stock', 'view-reports', 'void-sale'] as $izin) {
            $this->assertTrue($admin->can($izin), "Admin seharusnya boleh: $izin");
        }

        $this->assertFalse($admin->can('manage-users'));
        $this->assertFalse($admin->can('manage-settings'));
    }

    public function test_super_admin_memiliki_akses_penuh(): void
    {
        $super = $this->buatPengguna(RoleName::SuperAdmin);

        $this->assertTrue($super->can('manage-users'));
        $this->assertTrue($super->can('manage-settings'));
        $this->assertTrue($super->can('izin-yang-belum-dibuat'));
    }

    public function test_halaman_pengguna_hanya_dapat_dibuka_super_admin(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('users.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Admin))->get(route('users.index'))->assertForbidden();
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
