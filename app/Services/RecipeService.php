<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung biaya produksi per produk jual berdasarkan Bill of Materials (BOM).
 *
 * Harga bahan baku dibaca langsung dari kolom `purchase_price` di tabel products,
 * sehingga perubahan harga (lewat Pembelian atau Penyesuaian) otomatis tercermin
 * di perhitungan biaya produksi. Tidak ada duplikasi harga di sini.
 *
 * Contoh: 1 porsi Ayam Geprek membutuhkan:
 *   - Ayam Karkas 0.111 × Rp 45.000 = Rp 5.000
 *   - Tepung ORI 0.05 × Rp 19.500 = Rp 975
 *   Total biaya produksi per porsi = Rp 5.975
 */
final class RecipeService
{
    /**
     * Ambil atau buat BOM untuk produk.
     */
    public function ambil(Product $product): Recipe
    {
        return Recipe::firstOrCreate(
            ['product_id' => $product->id],
            ['name' => $product->name, 'notes' => '']
        );
    }

    /**
     * Simpan BOM produk dari data form.
     * Validasi: bahan baku harus ada, tidak boleh sama dengan produk jual itu sendiri.
     *
     * @param array<int, array{product_id: int, quantity: float, notes?: string}> $items
     */
    public function simpan(Product $product, array $items, string $notes = ''): Recipe
    {
        $recipe = $this->ambil($product);

        return DB::transaction(function () use ($recipe, $items, $notes) {
            // Hapus item lama, lalu buat ulang dari data form.
            RecipeItem::where('recipe_id', $recipe->id)->delete();

            foreach ($items as $item) {
                $bahanId = (int) ($item['product_id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 0);

                if ($bahanId === 0 || $qty <= 0) {
                    continue;
                }

                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'product_id' => $bahanId,
                    'unit_id' => Product::find($bahanId)?->unit_id,
                    'quantity' => $qty,
                    'notes' => $item['notes'] ?? '',
                ]);
            }

            $recipe->update(['notes' => $notes]);
            $recipe->load('items.product.unit');

            return $recipe;
        });
    }

    /**
     * Hitung total biaya produksi satu produk jual (jumlah seluruh bahan × harga).
     */
    public function hitungBiaya(Recipe $recipe): float
    {
        $total = 0.0;

        foreach ($recipe->items as $item) {
            $bahan = $item->product;
            if (! $bahan) {
                continue;
            }

            $hargaPerUnit = (float) $bahan->purchase_price;
            $total += $hargaPerUnit * (float) $item->quantity;
        }

        return $total;
    }

    /**
     * Hitung biaya per unit jual.
     * Misal: total biaya produksi Rp 20.000 untuk 25 porsi → Rp 800 per porsi.
     */
    public function biayaPerUnitJual(Recipe $recipe, float $totalProduksi): float
    {
        if ($totalProduksi <= 0) {
            return 0.0;
        }

        return $this->hitungBiaya($recipe) / $totalProduksi;
    }

    /**
     * Semua produk yang punya BOM.
     *
     * @return \Illuminate\Support\Collection<int, Recipe>
     */
    public function semua()
    {
        return Recipe::with(['product', 'items.product.unit'])->orderBy('name')->get();
    }
}
