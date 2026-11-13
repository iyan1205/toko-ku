<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RecipeService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Produk aktif ≠ dijual ≠ dibeli; produksi dari resep mengurangi bahan (1 pack karkas → 9 potong ayam). */
class ProduksiBahanBakuTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Product $karkas;

    private Product $dada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $kategori = Category::factory()->create(['name' => 'Ayam']);
        $this->karkas = Product::factory()->create(['name' => 'Karkas 1', 'type' => 'bahan_baku', 'unit_id' => Unit::factory()->create(['name' => 'PACK'])->id, 'category_id' => $kategori->id]);
        $this->dada = Product::factory()->create(['name' => 'Ayam Original Dada', 'is_purchasable' => false, 'selling_price' => 12000, 'unit_id' => Unit::factory()->create(['name' => 'PTG'])->id, 'category_id' => $kategori->id]);
        app(StockService::class)->catat($this->karkas, StockMovementType::Purchase, 3, null, null, $this->admin, null, ['unit_cost' => 4500000]); // 3 pack @45.000
        app(RecipeService::class)->simpan($this->dada, [['product_id' => $this->karkas->id, 'quantity' => 1]], '', 9);
    }

    private function produksi(string $jumlah, bool $produksi = true): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('stock.adjust.store'), [
            'warehouse_id' => Warehouse::default()->id, 'product_id' => $this->dada->id, 'type' => 'adjustment', 'direction' => 'in',
            'quantity' => $jumlah, 'note' => 'Potong ayam pagi', 'produksi' => $produksi ? '1' : '0',
        ]);
    }

    public function test_produk_bahan_baku_tidak_dijual_dan_olahan_tidak_dibeli(): void
    {
        $kasir = collect($this->actingAs($this->admin)->getJson(route('pos.products', ['q' => 'a']))->json())->pluck('name');
        $this->assertContains('Ayam Original Dada', $kasir);
        $this->assertNotContains('Karkas 1', $kasir);

        $beli = collect($this->getJson(route('purchases.products', ['q' => 'a']))->json())->pluck('name');
        $this->assertContains('Karkas 1', $beli);
        $this->assertNotContains('Ayam Original Dada', $beli);
        $this->assertContains('Ayam Original Dada', collect($this->getJson(route('stock-transfers.products', ['q' => 'a']))->json())->pluck('name'));

        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik)->put(route('products.update', $this->karkas), [
            'sku' => $this->karkas->sku, 'name' => 'Karkas 1', 'category_id' => $this->karkas->category_id, 'unit_id' => $this->karkas->unit_id,
            'purchase_price' => '45000', 'selling_price' => '0', 'min_stock' => '0', 'discount_percent' => '0',
            'is_active' => '1', 'is_sellable' => '0', 'is_purchasable' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['bahan_baku', true, true], [$this->karkas->fresh()->type, $this->karkas->fresh()->is_active, $this->karkas->fresh()->is_purchasable]);
        $this->get(route('products.index', ['jenis' => 'bahan_baku']))->assertOk()->assertSee('Karkas 1')->assertDontSee('Ayam Original Dada');
    }

    public function test_produksi_mengurangi_bahan_sesuai_resep_dan_hasil(): void
    {
        $this->produksi('9')->assertSessionHas('success', fn ($p) => str_contains($p, 'bahan yang dipakai: 1 Karkas 1'));
        $this->assertSame([2.0, 9.0], [(float) $this->karkas->fresh()->stock, (float) $this->dada->fresh()->stock]);
        $this->assertSame('5000.00', StockLot::query()->where('product_id', $this->dada->id)->sole()->unit_cost); // 45.000 / 9

        $this->produksi('2');
        $this->assertSame(1.778, (float) $this->karkas->fresh()->stock); // 2 − 2/9
        $this->assertSame(2, $this->dada->stockMovements()->where('type', 'production')->count());

        // Hanya koreksi hitungan: bahan tidak berubah.
        $this->produksi('1', produksi: false);
        $this->assertSame([1.778, 12.0], [(float) $this->karkas->fresh()->stock, (float) $this->dada->fresh()->stock]);
    }

    public function test_bahan_kurang_membatalkan_produksi(): void
    {
        $this->produksi('36')->assertSessionHas('error', fn ($p) => str_contains($p, 'Stok tidak mencukupi. Karkas 1'));
        $this->assertSame([3.0, 0.0], [(float) $this->karkas->fresh()->stock, (float) $this->dada->fresh()->stock]);
    }

    public function test_resep_menyimpan_hasil_dan_biaya_per_potong(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $paha = Product::factory()->create(['name' => 'Ayam Original Paha Atas', 'is_purchasable' => false]);
        $this->actingAs($pemilik)->post(route('recipes.store'), [
            'product_id' => $paha->id, 'yield_quantity' => '9', 'items' => [['product_id' => $this->karkas->id, 'quantity' => '1']],
        ])->assertSessionHasNoErrors();

        $resep = Recipe::query()->where('product_id', $paha->id)->sole();
        $this->assertSame('9.0000', $resep->yield_quantity);
        $this->karkas->update(['purchase_price' => 45000]);
        $this->assertEquals(5000, app(RecipeService::class)->hitungBiaya($resep->fresh('items.product')));
        $this->get(route('products.show', $paha))->assertOk()->assertSee('Resep produksi');
    }
}
