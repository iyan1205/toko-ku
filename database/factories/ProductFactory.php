<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.fake()->unique()->numerify('#####'),
            'barcode' => null,
            'name' => 'Produk '.fake()->unique()->numerify('####'),
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'purchase_price' => 8000,
            'selling_price' => 10000,
            'min_stock' => 5,
            'discount_percent' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Stok awal untuk keperluan tes, ditaruh di gudang utama (atau $gudang).
     * products.stock dan warehouse_stocks diisi bersamaan agar sama seperti hasil StockService.
     */
    public function stok(float|int $jumlah, ?Warehouse $gudang = null): static
    {
        return $this->state(fn () => ['stock' => $jumlah])
            ->afterCreating(function (Product $produk) use ($jumlah, $gudang) {
                if ($jumlah == 0) {
                    return;
                }

                $warehouseId = ($gudang ?? Warehouse::default())->getKey();
                (new WarehouseStock)->forceFill([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $produk->getKey(),
                    'quantity' => $jumlah,
                    'value' => round($jumlah * (float) $produk->purchase_price, 2),
                ])->save();
                // Seperti stok awal saat Fase 16 dipasang: satu lot dengan biaya = harga beli.
                if ($jumlah > 0) {
                    (new StockLot)->forceFill([
                        'product_id' => $produk->getKey(), 'warehouse_id' => $warehouseId, 'received_at' => now()->subDay(),
                        'unit_cost' => $produk->purchase_price, 'quantity_in' => $jumlah, 'quantity' => $jumlah,
                    ])->save();
                }
            });
    }
}
