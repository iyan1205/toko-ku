<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class HalamanSegeraHadirTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
    }

    public function test_menu_yang_belum_tersedia_menampilkan_halaman_segera_hadir(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Akuntan))
            ->get(route('coming-soon', 'reports-gross-profit'))
            ->assertOk()
            ->assertSee('Laba Kotor')
            ->assertSee('Laba kotor per produk, kategori, dan periode.')
            ->assertSee('Fitur ini sedang disiapkan');
    }

    public function test_key_tidak_dikenal_atau_menu_yang_sudah_aktif_tidak_ditemukan(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::SuperAdmin));

        $this->get(route('coming-soon', 'tidak-ada'))->assertNotFound();
        $this->get(route('coming-soon', 'inventory-products'))->assertNotFound();
        $this->get(route('coming-soon', 'accounting-coa'))->assertNotFound();
        $this->get(route('coming-soon', 'accounting-reports'))->assertNotFound();
    }

    public function test_pengguna_tanpa_hak_akses_menu_ditolak(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->get(route('coming-soon', 'reports-gross-profit'))
            ->assertForbidden();
    }
}
