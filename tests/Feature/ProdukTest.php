<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StatusStok;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class ProdukTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;
    private User $kasir;
    private Category $kategori;
    private Unit $satuan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->kategori = Category::factory()->create();
        $this->satuan = Unit::factory()->create();
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'sku' => '',
            'barcode' => '',
            'name' => 'Ayam Geprek',
            'category_id' => $this->kategori->id,
            'unit_id' => $this->satuan->id,
            'purchase_price' => '8000',
            'selling_price' => '10000',
            'min_stock' => '5',
            'discount_percent' => '0',
            'description' => '',
            'is_active' => '1',
        ], $ubah);
    }

    public function test_admin_membuat_produk_dengan_sku_otomatis_dan_stok_nol(): void
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->payload())
            ->assertSessionHas('success');

        $produk = Product::firstOrFail();

        $this->assertSame('PRD-00001', $produk->sku);
        $this->assertSame(8000.0, (float) $produk->purchase_price);
        $this->assertSame(10000.0, (float) $produk->selling_price);
        $this->assertSame(0.0, (float) $produk->stock);
        $this->assertNull($produk->barcode);
        $this->assertTrue($produk->is_active);
    }

    public function test_sku_otomatis_berurutan_dan_tidak_dipakai_ulang(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('products.store'), $this->payload(['name' => 'A']));
        $this->post(route('products.store'), $this->payload(['name' => 'B']));
        Product::where('sku', 'PRD-00002')->firstOrFail()->delete();
        $this->post(route('products.store'), $this->payload(['name' => 'C']));

        $this->assertEqualsCanonicalizing(
            ['PRD-00001', 'PRD-00002', 'PRD-00003'],
            Product::withTrashed()->pluck('sku')->all()
        );
    }

    public function test_sku_manual_diubah_menjadi_huruf_besar(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->payload(['sku' => 'ayam-01']));

        $this->assertDatabaseHas('products', ['sku' => 'AYAM-01']);
    }

    public function test_sku_harus_unik_termasuk_produk_yang_sudah_dihapus(): void
    {
        Product::factory()->create(['sku' => 'ABC-1'])->delete();

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->payload(['sku' => 'abc-1']))
            ->assertSessionHasErrors('sku');

        $this->assertSame(1, Product::withTrashed()->count());
    }

    public function test_barcode_boleh_kosong_berulang_tetapi_unik_bila_diisi(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('products.store'), $this->payload(['name' => 'A']))->assertSessionHasNoErrors();
        $this->post(route('products.store'), $this->payload(['name' => 'B']))->assertSessionHasNoErrors();

        $this->post(route('products.store'), $this->payload(['name' => 'C', 'barcode' => '8991234567890']))->assertSessionHasNoErrors();
        $this->post(route('products.store'), $this->payload(['name' => 'D', 'barcode' => '8991234567890']))->assertSessionHasErrors('barcode');

        $this->assertSame(3, Product::count());
    }

    public function test_stok_tidak_dapat_diisi_lewat_formulir(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), $this->payload(['stock' => 99]));

        $this->assertSame(0.0, (float) Product::firstOrFail()->stock);

        $produk = Product::firstOrFail();
        $this->put(route('products.update', $produk), $this->payload(['stock' => 55]));

        $this->assertSame(0.0, (float) $produk->fresh()->stock);
    }

    public function test_validasi_menolak_data_yang_salah(): void
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->payload([
                'name' => '',
                'category_id' => 9999,
                'selling_price' => '-5',
                'discount_percent' => '150',
            ]))
            ->assertSessionHasErrors(['name', 'category_id', 'selling_price', 'discount_percent']);

        $this->assertSame(0, Product::count());
    }

    public function test_foto_dapat_diunggah_diganti_dan_dihapus(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin);

        $this->post(route('products.store'), $this->payload(['photo' => UploadedFile::fake()->image('a.jpg', 200, 200)]));
        $produk = Product::firstOrFail();
        $fotoLama = $produk->photo;

        $this->assertNotNull($fotoLama);
        Storage::disk('public')->assertExists($fotoLama);

        $this->put(route('products.update', $produk), $this->payload(['photo' => UploadedFile::fake()->image('b.png', 200, 200)]));
        $produk->refresh();

        Storage::disk('public')->assertMissing($fotoLama);
        Storage::disk('public')->assertExists($produk->photo);

        $fotoBaru = $produk->photo;
        $this->put(route('products.update', $produk), $this->payload(['remove_photo' => '1']));

        $this->assertNull($produk->fresh()->photo);
        Storage::disk('public')->assertMissing($fotoBaru);
    }

    public function test_berkas_bukan_gambar_ditolak(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->payload(['photo' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php')]))
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, Product::count());
    }

    public function test_sku_dipertahankan_bila_dikosongkan_saat_mengubah(): void
    {
        $produk = Product::factory()->create(['sku' => 'KEEP-1', 'category_id' => $this->kategori->id, 'unit_id' => $this->satuan->id]);

        $this->actingAs($this->admin)
            ->put(route('products.update', $produk), $this->payload(['name' => 'Nama Baru', 'sku' => '']))
            ->assertSessionHasNoErrors();

        $produk->refresh();
        $this->assertSame('KEEP-1', $produk->sku);
        $this->assertSame('Nama Baru', $produk->name);
    }

    public function test_penghapusan_produk_adalah_soft_delete(): void
    {
        $produk = Product::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('products.destroy', $produk))
            ->assertRedirect(route('products.index'));

        $this->assertSoftDeleted('products', ['id' => $produk->id]);
    }

    public function test_kasir_melihat_produk_tetapi_tidak_melihat_harga_beli(): void
    {
        $produk = Product::factory()->create(['name' => 'Kopi Susu', 'purchase_price' => 7777, 'selling_price' => 12345]);

        $this->actingAs($this->kasir)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Kopi Susu')
            ->assertSee('Rp 12.345')
            ->assertDontSee('Rp 7.777')
            ->assertDontSee('Tambah produk');

        $this->get(route('products.show', $produk))
            ->assertOk()
            ->assertSee('Rp 12.345')
            ->assertDontSee('Rp 7.777');

        $this->actingAs($this->admin)
            ->get(route('products.index'))
            ->assertSee('Rp 7.777');
    }

    public function test_kasir_tidak_dapat_membuat_mengubah_atau_menghapus_produk(): void
    {
        $produk = Product::factory()->create();

        $this->actingAs($this->kasir);
        $this->get(route('products.create'))->assertForbidden();
        $this->post(route('products.store'), $this->payload())->assertForbidden();
        $this->get(route('products.edit', $produk))->assertForbidden();
        $this->put(route('products.update', $produk), $this->payload())->assertForbidden();
        $this->delete(route('products.destroy', $produk))->assertForbidden();

        $this->assertSame(1, Product::count());
        $this->assertNotSame('Ayam Geprek', $produk->fresh()->name);
    }

    public function test_filter_stok_habis_menipis_dan_aman(): void
    {
        Product::factory()->stok(0)->create(['name' => 'Produk Habis', 'min_stock' => 5]);
        Product::factory()->stok(3)->create(['name' => 'Produk Menipis', 'min_stock' => 5]);
        Product::factory()->stok(50)->create(['name' => 'Produk Aman', 'min_stock' => 5]);

        $this->actingAs($this->admin);

        $this->get(route('products.index', ['stok' => 'habis']))
            ->assertSee('Produk Habis')->assertDontSee('Produk Menipis')->assertDontSee('Produk Aman');

        $this->get(route('products.index', ['stok' => 'menipis']))
            ->assertSee('Produk Menipis')->assertDontSee('Produk Habis')->assertDontSee('Produk Aman');

        $this->get(route('products.index', ['stok' => 'aman']))
            ->assertSee('Produk Aman')->assertDontSee('Produk Habis')->assertDontSee('Produk Menipis');
    }

    public function test_status_stok_dihitung_dengan_benar_di_batas(): void
    {
        $this->assertSame(StatusStok::Habis, Product::factory()->stok(0)->make(['min_stock' => 5])->statusStok());
        $this->assertSame(StatusStok::Menipis, Product::factory()->stok(5)->make(['min_stock' => 5])->statusStok());
        $this->assertSame(StatusStok::Aman, Product::factory()->stok(6)->make(['min_stock' => 5])->statusStok());
        $this->assertSame(StatusStok::Aman, Product::factory()->stok(1)->make(['min_stock' => 0])->statusStok());
    }

    public function test_pencarian_berdasarkan_nama_sku_dan_barcode(): void
    {
        Product::factory()->create(['name' => 'Teh Botol', 'sku' => 'TEH-001', 'barcode' => '8990000000011']);
        Product::factory()->create(['name' => 'Roti Tawar', 'sku' => 'ROT-001', 'barcode' => '8990000000022']);

        $this->actingAs($this->admin);

        $this->get(route('products.index', ['cari' => 'Teh']))->assertSee('Teh Botol')->assertDontSee('Roti Tawar');
        $this->get(route('products.index', ['cari' => 'rot-001']))->assertSee('Roti Tawar')->assertDontSee('Teh Botol');
        $this->get(route('products.index', ['cari' => '8990000000011']))->assertSee('Teh Botol')->assertDontSee('Roti Tawar');
    }

    public function test_filter_status_aktif_dan_nonaktif(): void
    {
        Product::factory()->create(['name' => 'Barang Aktif', 'is_active' => true]);
        Product::factory()->create(['name' => 'Barang Mati', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('products.index', ['status' => 'nonaktif']))
            ->assertSee('Barang Mati')
            ->assertDontSee('Barang Aktif');
    }

    public function test_harga_setelah_diskon(): void
    {
        $produk = Product::factory()->make(['selling_price' => 20000, 'discount_percent' => 15]);

        $this->assertSame(17000.0, $produk->hargaSetelahDiskon());
    }
}
