<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class KategoriSatuanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
    }

    public function test_admin_dapat_menambah_kategori(): void
    {
        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => 'Makanan', 'description' => 'Makanan jadi'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', ['name' => 'Makanan', 'description' => 'Makanan jadi']);
    }

    public function test_nama_kategori_harus_unik_dengan_pesan_indonesia(): void
    {
        Category::factory()->create(['name' => 'Makanan']);

        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => 'Makanan'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Nama kategori ini sudah ada.', session('errors')->first('name'));
    }

    public function test_kategori_dapat_diubah_tanpa_bentrok_dengan_namanya_sendiri(): void
    {
        $kategori = Category::factory()->create(['name' => 'Makanan']);

        $this->actingAs($this->admin)
            ->put(route('categories.update', $kategori), ['name' => 'Makanan', 'description' => 'Diubah'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Diubah', $kategori->fresh()->description);
    }

    public function test_kategori_yang_dipakai_produk_tidak_dapat_dihapus(): void
    {
        $kategori = Category::factory()->create();
        Product::factory()->create(['category_id' => $kategori->id]);

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $kategori->id]);
    }

    public function test_kategori_tetap_tertahan_walau_produknya_sudah_dihapus(): void
    {
        $kategori = Category::factory()->create();
        Product::factory()->create(['category_id' => $kategori->id])->delete();

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $kategori->id]);
    }

    public function test_kategori_yang_tidak_dipakai_dapat_dihapus(): void
    {
        $kategori = Category::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $kategori))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $kategori->id]);
    }

    public function test_nama_satuan_disimpan_dengan_huruf_besar(): void
    {
        $this->actingAs($this->admin)
            ->post(route('units.store'), ['name' => ' pcs '])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('units', ['name' => 'PCS']);
    }

    public function test_satuan_unik_tanpa_membedakan_huruf(): void
    {
        Unit::factory()->create(['name' => 'PCS']);

        $this->actingAs($this->admin)
            ->post(route('units.store'), ['name' => 'pcs'])
            ->assertSessionHasErrors('name');
    }

    public function test_satuan_yang_dipakai_produk_tidak_dapat_dihapus(): void
    {
        $satuan = Unit::factory()->create();
        Product::factory()->create(['unit_id' => $satuan->id]);

        $this->actingAs($this->admin)
            ->delete(route('units.destroy', $satuan))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('units', ['id' => $satuan->id]);
    }

    public function test_halaman_daftar_tampil_untuk_admin(): void
    {
        Category::factory()->create(['name' => 'Sembako']);

        $this->actingAs($this->admin)->get(route('categories.index'))->assertOk()->assertSee('Sembako')->assertSee('Tambah kategori');
        $this->get(route('units.index'))->assertOk()->assertSee('Tambah satuan');
    }

    public function test_kasir_tidak_dapat_mengakses_kategori_dan_satuan(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasir)->get(route('categories.index'))->assertForbidden();
        $this->post(route('categories.store'), ['name' => 'X'])->assertForbidden();
        $this->get(route('units.index'))->assertForbidden();
        $this->post(route('units.store'), ['name' => 'X'])->assertForbidden();
    }
}
