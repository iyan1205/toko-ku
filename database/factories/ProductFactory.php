<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => 'SKU-' . fake()->unique()->numerify('#####'),
            'barcode' => null,
            'name' => 'Produk ' . fake()->unique()->numerify('####'),
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'purchase_price' => 8000,
            'selling_price' => 10000,
            'min_stock' => 5,
            'discount_percent' => 0,
            'is_active' => true,
        ];
    }

    /** Factory berjalan unguarded, jadi `stock` boleh diisi di sini untuk keperluan tes. */
    public function stok(float|int $jumlah): static
    {
        return $this->state(fn () => ['stock' => $jumlah]);
    }
}
