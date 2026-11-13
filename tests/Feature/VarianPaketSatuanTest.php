<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\Settings\StoreProfileService;
use App\Support\StrukTeks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 16b: multi-satuan (kasir & pembelian), varian produk, produk paket. */
class VarianPaketSatuanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Unit $pcs;

    private Unit $dus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Pemilik);
        $this->pcs = Unit::factory()->create(['name' => 'PCS']);
        $this->dus = Unit::factory()->create(['name' => 'DUS']);
        Customer::umum();
    }

    private function jual(array $items): Sale
    {
        return app(SaleService::class)->buat([
            'customer_id' => null, 'items' => $items, 'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '10000000',
        ], $this->admin);
    }

    private function mie(): Product
    {
        $mie = Product::factory()->stok(100)->create(['name' => 'Mie Instan', 'unit_id' => $this->pcs->id, 'selling_price' => 3500, 'purchase_price' => 2500]);
        $mie->units()->create(['unit_id' => $this->dus->id, 'factor' => 40, 'barcode' => 'DUS-MIE', 'selling_price' => 125000]);

        return $mie;
    }

    public function test_kasir_menjual_per_dus_dari_barcode_dus_dan_stok_berkurang_dalam_pcs(): void
    {
        $mie = $this->mie();

        $hasil = $this->actingAs($this->admin)->getJson(route('pos.products', ['q' => 'DUS-MIE']))->assertOk()->json();
        $this->assertTrue($hasil[0]['exact']);
        $this->assertSame($this->dus->id, $hasil[0]['exact_unit_id']);
        $this->assertEquals(125000, $hasil[0]['units'][0]['price']);

        $sale = $this->jual([
            ['product_id' => $mie->id, 'unit_id' => $this->dus->id, 'quantity' => '2', 'discount' => '0'],
            ['product_id' => $mie->id, 'quantity' => '3', 'discount' => '0'],
        ]);

        $this->assertSame('260500.00', $sale->grand_total); // 2 × 125.000 + 3 × 3.500
        $this->assertSame(17.0, (float) $mie->fresh()->stock); // 100 - 80 - 3
        $dus = $sale->items->firstWhere('sale_unit_id', $this->dus->id);
        $this->assertSame(['80.000', 'DUS', '2.000', '125000.00', '2500.00'], [$dus->quantity, $dus->sale_unit_name, $dus->sale_unit_quantity, $dus->sale_unit_price, $dus->cost_price]);
        $teks = (new StrukTeks($sale->fresh(['customer', 'user', 'items']), app(StoreProfileService::class)->ambil()))->susun();
        $this->assertStringContainsString('2 DUS x 125.000', $teks);
    }

    public function test_pembelian_per_dus_disimpan_dalam_satuan_dasar(): void
    {
        $mie = $this->mie();

        $beli = app(PurchaseService::class)->buat([
            'supplier_id' => Supplier::factory()->create()->id, 'warehouse_id' => Warehouse::default()->id, 'purchase_date' => now()->toDateString(),
            'items' => [['product_id' => $mie->id, 'unit_id' => $this->dus->id, 'quantity' => '3', 'unit_price' => '100000', 'discount' => '0']],
            'payment_method' => 'cash', 'paid_amount' => '300000',
        ], $this->admin);

        $item = $beli->items->sole();
        $this->assertSame(['120.000', '2500.00', '300000.00'], [$item->quantity, $item->unit_price, $item->line_total]);
        $this->assertSame(220.0, (float) $mie->fresh()->stock);
    }

    public function test_varian_dibuat_dari_atribut_dan_induk_tidak_dijual(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), [
            'sku' => 'KAOS', 'name' => 'Kaos Polos', 'category_id' => Product::factory()->create()->category_id, 'unit_id' => $this->pcs->id,
            'purchase_price' => '30000', 'selling_price' => '60000', 'min_stock' => '0', 'discount_percent' => '0', 'is_active' => '1',
            'variant_options' => [['name' => 'Ukuran', 'values' => 'M, L'], ['name' => 'Warna', 'values' => 'Hitam, Putih']],
        ])->assertSessionHasNoErrors();

        $induk = Product::query()->where('sku', 'KAOS')->sole();
        $this->assertSame(4, $induk->variants()->count());
        $varian = $induk->variants()->where('sku', 'KAOS-M-HITAM')->sole();
        $this->assertSame(['Kaos Polos M / Hitam', '60000.00', ['Ukuran' => 'M', 'Warna' => 'Hitam']], [$varian->name, $varian->selling_price, $varian->variant_values]);

        $nama = collect($this->getJson(route('pos.products', ['q' => 'Kaos']))->json())->pluck('name');
        $this->assertNotContains('Kaos Polos', $nama);
        $this->assertContains('Kaos Polos L / Putih', $nama);

        // Nilai yang dihapus: variannya dinonaktifkan, tidak dihapus.
        $this->put(route('products.update', $induk), [
            'sku' => 'KAOS', 'name' => 'Kaos Polos', 'category_id' => $induk->category_id, 'unit_id' => $this->pcs->id,
            'purchase_price' => '30000', 'selling_price' => '60000', 'min_stock' => '0', 'discount_percent' => '0', 'is_active' => '1',
            'variant_options' => [['name' => 'Ukuran', 'values' => 'M'], ['name' => 'Warna', 'values' => 'Hitam, Putih']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $induk->variants()->where('is_active', true)->count());
        $this->assertSame(4, $induk->variants()->count());
        $this->get(route('products.show', $induk))->assertOk()->assertSee('M / Hitam');
    }

    public function test_paket_mengurangi_stok_komponen_dan_pembatalan_mengembalikannya(): void
    {
        $nasi = Product::factory()->stok(10)->create(['name' => 'Nasi', 'purchase_price' => 3000]);
        $ayam = Product::factory()->stok(10)->create(['name' => 'Ayam', 'purchase_price' => 8000]);
        $paket = Product::factory()->create(['name' => 'Paket Hemat', 'selling_price' => 20000, 'is_bundle' => true]);
        $resep = Recipe::query()->create(['product_id' => $paket->id, 'name' => 'Paket Hemat', 'notes' => '']);
        $resep->items()->create(['product_id' => $nasi->id, 'unit_id' => $nasi->unit_id, 'quantity' => 2, 'notes' => '']);
        $resep->items()->create(['product_id' => $ayam->id, 'unit_id' => $ayam->unit_id, 'quantity' => 1, 'notes' => '']);

        $hasil = collect($this->actingAs($this->admin)->getJson(route('pos.products', ['q' => 'Paket']))->json())->firstWhere('id', $paket->id);
        $this->assertEquals(5, $hasil['stock']); // nasi 10/2, ayam 10/1

        $sale = $this->jual([['product_id' => $paket->id, 'quantity' => '3', 'discount' => '0']]);

        $this->assertSame([4.0, 7.0, 0.0], [(float) $nasi->fresh()->stock, (float) $ayam->fresh()->stock, (float) $paket->fresh()->stock]);
        $this->assertSame('14000.00', $sale->items->sole()->cost_price); // 2 × 3.000 + 8.000

        app(SaleService::class)->batalkan($sale, 'Salah pesanan', $this->admin);
        $this->assertSame([10.0, 10.0], [(float) $nasi->fresh()->stock, (float) $ayam->fresh()->stock]);
    }

    public function test_barcode_satuan_unik_dan_induk_varian_tidak_boleh_berstok(): void
    {
        $mie = $this->mie();
        $lain = Product::factory()->stok(5)->create(['unit_id' => $this->pcs->id]);
        $data = fn (array $ubah) => array_merge([
            'sku' => $lain->sku, 'name' => $lain->name, 'category_id' => $lain->category_id, 'unit_id' => $this->pcs->id,
            'purchase_price' => '1000', 'selling_price' => '2000', 'min_stock' => '0', 'discount_percent' => '0', 'is_active' => '1',
        ], $ubah);

        $this->actingAs($this->admin)->put(route('products.update', $lain), $data(['units' => [['unit_id' => $this->dus->id, 'factor' => '10', 'barcode' => 'DUS-MIE']]]))
            ->assertSessionHasErrors('units.0.barcode');
        $this->put(route('products.update', $lain), $data(['variant_options' => [['name' => 'Rasa', 'values' => 'Asin']]]))
            ->assertSessionHasErrors('variant_options');
        $this->put(route('products.update', $lain), $data(['units' => [['unit_id' => $this->dus->id, 'factor' => '10', 'barcode' => 'DUS-LAIN', 'selling_price' => '']]]))
            ->assertSessionHasNoErrors();
        $this->assertSame('10.0000', $lain->units()->sole()->factor);
        $this->assertNotNull($mie);
    }
}
