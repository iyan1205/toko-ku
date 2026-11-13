<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductBreakdown;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Pecah bahan: 1 pack Karkas 1 = 3 dada, 2 paha atas, 2 paha bawah, 2 sayap (9 potong) sekaligus. */
class PecahBahanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private Product $karkas;

    /** @var array<string, Product> */
    private array $potong = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        $ptg = Unit::factory()->create(['name' => 'PTG']);
        $this->karkas = Product::factory()->create(['name' => 'Karkas 1', 'type' => 'bahan_baku', 'unit_id' => Unit::factory()->create(['name' => 'PACK'])->id]);
        foreach (['Dada' => 15000, 'Paha Atas' => 13000, 'Paha Bawah' => 12000, 'Sayap' => 10000] as $nama => $harga) {
            $this->potong[$nama] = Product::factory()->create(['name' => "Ayam Original {$nama}", 'unit_id' => $ptg->id, 'selling_price' => $harga, 'is_purchasable' => false]);
        }
        app(StockService::class)->catat($this->karkas, StockMovementType::Purchase, 5, null, null, $this->pemilik, null, ['unit_cost' => 4500000]); // 5 pack @45.000

        $this->actingAs($this->pemilik)->post(route('breakdowns.store'), [
            'source_product_id' => $this->karkas->id, 'source_quantity' => '1', 'cost_method' => 'quantity', 'is_active' => '1',
            'outputs' => [
                ['product_id' => $this->potong['Dada']->id, 'quantity' => '3'],
                ['product_id' => $this->potong['Paha Atas']->id, 'quantity' => '2'],
                ['product_id' => $this->potong['Paha Bawah']->id, 'quantity' => '2'],
                ['product_id' => $this->potong['Sayap']->id, 'quantity' => '2'],
            ],
        ])->assertSessionHasNoErrors();
    }

    /** @return list<float> stok karkas, dada, paha atas, paha bawah, sayap */
    private function stok(): array
    {
        return [(float) $this->karkas->fresh()->stock, ...array_map(fn (Product $p) => (float) $p->fresh()->stock, array_values($this->potong))];
    }

    private function sesuaikan(Product $p, string $jumlah): TestResponse
    {
        return $this->post(route('stock.adjust.store'), [
            'warehouse_id' => Warehouse::default()->id, 'product_id' => $p->id, 'type' => 'adjustment', 'direction' => 'in',
            'quantity' => $jumlah, 'note' => 'Potong pagi', 'produksi' => '1',
        ]);
    }

    public function test_pecah_dua_pack_menambah_semua_potongan_dan_membagi_biaya_rata(): void
    {
        $b = ProductBreakdown::query()->sole();
        $this->get(route('breakdowns.index'))->assertOk()->assertSee('3 PTG Ayam Original Dada')->assertSee('Total 9 hasil');

        $this->post(route('breakdowns.run', $b), ['warehouse_id' => Warehouse::default()->id, 'times' => '2'])
            ->assertSessionHas('success', 'Dipecah: 2 Karkas 1 menjadi 6 Ayam Original Dada, 4 Ayam Original Paha Atas, 4 Ayam Original Paha Bawah, 4 Ayam Original Sayap.');

        $this->assertSame([3.0, 6.0, 4.0, 4.0, 4.0], $this->stok());
        // 2 pack × 45.000 = 90.000 untuk 18 potong = 5.000 per potong.
        foreach ($this->potong as $p) {
            $this->assertSame('5000.00', StockLot::query()->where('product_id', $p->id)->sole()->unit_cost);
        }
    }

    public function test_menyesuaikan_stok_dada_memecah_pack_utuh_dan_potongan_lain_ikut_bertambah(): void
    {
        $this->sesuaikan($this->potong['Dada'], '6')->assertSessionHas('success', fn ($p) => str_contains($p, 'Dipecah 2 Karkas 1'));
        $this->assertSame([3.0, 6.0, 4.0, 4.0, 4.0], $this->stok());

        $this->sesuaikan($this->potong['Sayap'], '2')->assertSessionHas('success');
        $this->assertSame([2.0, 9.0, 6.0, 6.0, 6.0], $this->stok());

        $this->sesuaikan($this->potong['Dada'], '4')->assertSessionHas('error', fn ($p) => str_contains($p, 'harus kelipatan 3'));
        $this->sesuaikan($this->potong['Dada'], '9')->assertSessionHas('error', fn ($p) => str_contains($p, 'Stok tidak mencukupi. Karkas 1'));
        $this->assertSame([2.0, 9.0, 6.0, 6.0, 6.0], $this->stok());
    }

    public function test_biaya_sebanding_harga_jual_dan_aturan_definisi(): void
    {
        $b = ProductBreakdown::query()->sole();
        $b->update(['cost_method' => 'price']);
        $this->post(route('breakdowns.run', $b), ['warehouse_id' => Warehouse::default()->id, 'times' => '1']);
        // Bobot: 3×15.000 + 2×13.000 + 2×12.000 + 2×10.000 = 115.000; dada = 45.000 × 45.000/115.000 = 17.608,70 → 5.869,57 per potong.
        $this->assertSame('5869.57', StockLot::query()->where('product_id', $this->potong['Dada']->id)->sole()->unit_cost);
        $total = StockLot::query()->whereIn('product_id', collect($this->potong)->pluck('id'))->get()->sum(fn ($l) => (float) $l->unit_cost * (float) $l->quantity_in);
        $this->assertEqualsWithDelta(45000, $total, 0.05);

        $tepung = Product::factory()->create(['name' => 'Tepung']);
        $this->post(route('breakdowns.store'), [
            'source_product_id' => $tepung->id, 'source_quantity' => '1', 'cost_method' => 'quantity', 'is_active' => '1',
            'outputs' => [['product_id' => $this->potong['Dada']->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('outputs.0.product_id');

        $this->get(route('products.show', $this->potong['Paha Bawah']))->assertOk()->assertSee('Hasil pemecahan')->assertSee('2 Ayam Original Paha Bawah');
        $this->get(route('products.show', $this->karkas))->assertOk()->assertSee('Dipecah menjadi');
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('breakdowns.index'))->assertForbidden();
    }
}
