<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class NotifikasiStokTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
    }

    public function test_daftar_berisi_produk_aktif_di_bawah_stok_minimum_mulai_dari_yang_habis(): void
    {
        Product::factory()->stok(3)->create(['name' => 'Tempe', 'min_stock' => 5]);
        Product::factory()->stok(0)->create(['name' => 'Tahu', 'min_stock' => 5]);
        Product::factory()->stok(50)->create(['name' => 'Nasi', 'min_stock' => 5]);
        Product::factory()->stok(0)->create(['name' => 'Produk Lama', 'min_stock' => 5, 'is_active' => false]);

        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->getJson(route('notifications'))
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('items.*.title', ['Tahu', 'Tempe'])
            ->assertJsonPath('items.*.status', ['habis', 'menipis']);
    }

    public function test_pengguna_tanpa_izin_stok_ditolak(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('notifications'))
            ->assertForbidden();
    }

    public function test_ikon_notifikasi_menampilkan_jumlah_produk_yang_perlu_diisi_ulang(): void
    {
        Product::factory()->stok(0)->count(2)->create(['min_stock' => 5]);

        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Notifikasi: 2 produk perlu diisi ulang"', false);
    }
}
