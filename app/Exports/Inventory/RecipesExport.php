<?php

namespace App\Exports\Inventory;

use App\Exports\ListExport;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\RecipeService;
use Illuminate\Database\Eloquent\Builder;

/** Resep (BOM) per produk jual beserta biaya bahan dan margin. Biaya mengikuti harga beli bahan saat ini. */
class RecipesExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Resep (BOM)';
    }

    protected function baseQuery(): Builder
    {
        return Recipe::query()
            ->with(['product' => fn ($q) => $q->withTrashed(), 'items.product'])
            ->withCount('items')
            ->when($this->filters['cari'] ?? null, fn (Builder $q, string $kata) => $q->whereHas('product', fn (Builder $p) => $p->cari($kata)));
    }

    protected function defaultOrder(Builder $query): void
    {
        $this->sortByProduct($query, 'asc');
    }

    protected function sorts(): array
    {
        return [
            'product' => fn (Builder $q, string $dir) => $this->sortByProduct($q, $dir),
            'items' => 'items_count',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'SKU', 'value' => fn (Recipe $r) => $r->product?->sku],
            ['label' => 'Produk', 'value' => fn (Recipe $r) => $r->product?->name ?? 'Produk dihapus'],
            ['label' => 'Jumlah Bahan', 'value' => fn (Recipe $r) => $r->items_count, 'type' => 'qty'],
            ['label' => 'Biaya Bahan', 'value' => fn (Recipe $r) => $this->cost($r), 'type' => 'money'],
            ['label' => 'Harga Jual', 'value' => fn (Recipe $r) => $r->product?->selling_price, 'type' => 'money'],
            ['label' => 'Margin', 'value' => fn (Recipe $r) => $this->margin($r), 'type' => 'money'],
        ];
    }

    public function cost(Recipe $recipe): float
    {
        return app(RecipeService::class)->hitungBiaya($recipe);
    }

    public function margin(Recipe $recipe): float
    {
        return round((float) ($recipe->product?->selling_price ?? 0) - $this->cost($recipe), 2);
    }

    protected function filterSummary(): array
    {
        return array_filter(['Pencarian' => $this->filters['cari'] ?? null]);
    }

    private function sortByProduct(Builder $query, string $dir): void
    {
        $query->orderBy(Product::withTrashed()->select('name')->whereColumn('products.id', 'recipes.product_id'), $dir);
    }
}
