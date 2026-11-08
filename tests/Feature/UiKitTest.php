<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class UiKitTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
    }

    public function test_tamu_diarahkan_ke_halaman_login(): void
    {
        $this->app['env'] = 'local';

        $this->get(route('dev.ui-kit'))->assertRedirect(route('login'));
    }

    public function test_halaman_tidak_ditemukan_di_luar_environment_local_walau_super_admin(): void
    {
        $superAdmin = $this->buatPengguna(RoleName::SuperAdmin);

        $this->actingAs($superAdmin)->get(route('dev.ui-kit'))->assertNotFound();
    }

    public function test_selain_super_admin_ditolak_di_environment_local(): void
    {
        $this->app['env'] = 'local';
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('dev.ui-kit'))->assertForbidden();
    }

    public function test_super_admin_melihat_katalog_komponen_di_environment_local(): void
    {
        $this->app['env'] = 'local';
        $superAdmin = $this->buatPengguna(RoleName::SuperAdmin);

        $this->actingAs($superAdmin)
            ->get(route('dev.ui-kit'))
            ->assertOk()
            ->assertSee('Halaman internal pengembang')
            ->assertSee('Nama produk wajib diisi.');
    }

    public function test_tabel_contoh_bisa_dicari_dan_diurutkan(): void
    {
        $this->app['env'] = 'local';
        $superAdmin = $this->buatPengguna(RoleName::SuperAdmin);

        $this->actingAs($superAdmin)
            ->get(route('dev.ui-kit', ['cari' => 'ayam', 'sort' => 'price', 'dir' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['MKN-003', 'MKN-002', 'MKN-001'])
            ->assertDontSee('MKN-004');
    }
}
