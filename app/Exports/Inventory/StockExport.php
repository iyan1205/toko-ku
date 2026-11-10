<?php

namespace App\Exports\Inventory;

use App\Exports\ListExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Posisi stok: produk × gudang. Tanpa filter gudang, semua gudang aktif menjadi kolom.
 * Status stok (aman/menipis/habis) memakai total semua gudang terhadap stok minimum produk.
 * Harga beli dan nilai persediaan hanya disertakan bila withCost(true).
 */
class StockExport extends ListExport
{
    private bool $withCost = false;

    /** @var Collection<int, Warehouse>|null */
    private ?Collection $warehouses = null;

    public function withCost(bool $withCost = true): static
    {
        $this->withCost = $withCost;

        return $this;
    }

    public function documentTitle(): string
    {
        return 'Posisi Stok';
    }

    /** Gudang yang menjadi kolom tabel. @return Collection<int, Warehouse> */
    public function warehouses(): Collection
    {
        return $this->warehouses ??= isset($this->filters['gudang'])
            ? Warehouse::withTrashed()->whereKey($this->filters['gudang'])->get()
            : Warehouse::query()->active()->ordered()->get();
    }

    protected function baseQuery(): Builder
    {
        $ids = $this->warehouses()->modelKeys();

        return Product::query()
            ->with(['category:id,name', 'unit:id,name', 'warehouseStocks' => fn ($q) => $q->whereIn('warehouse_id', $ids)])
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('name');
    }

    protected function sorts(): array
    {
        $warehouseId = $this->filters['gudang'] ?? null;

        return [
            'name' => 'name',
            'sku' => 'sku',
            'category' => fn (Builder $q, string $dir) => $q->orderBy(Category::select('name')->whereColumn('categories.id', 'products.category_id'), $dir),
            // Dengan filter gudang, urut berdasarkan stok di gudang itu; tanpa filter, total semua gudang.
            'stock' => $warehouseId
                ? fn (Builder $q, string $dir) => $q->orderBy(WarehouseStock::selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('warehouse_stocks.product_id', 'products.id')->where('warehouse_id', $warehouseId), $dir)
                : 'stock',
            'value' => fn (Builder $q, string $dir) => $q->orderByRaw('(stock * purchase_price) '.($dir === 'desc' ? 'desc' : 'asc')),
        ];
    }

    protected function columns(): array
    {
        $columns = [
            ['label' => 'SKU', 'value' => fn (Product $p) => $p->sku],
            ['label' => 'Produk', 'value' => fn (Product $p) => $p->name],
            ['label' => 'Kategori', 'value' => fn (Product $p) => $p->category?->name],
            ['label' => 'Satuan', 'value' => fn (Product $p) => $p->unit?->name],
        ];

        foreach ($this->warehouses() as $warehouse) {
            $columns[] = ['label' => $warehouse->name, 'value' => fn (Product $p) => $p->stockIn($warehouse), 'type' => 'qty'];
        }

        if (! isset($this->filters['gudang'])) {
            $columns[] = ['label' => 'Total Stok', 'value' => fn (Product $p) => $p->stock, 'type' => 'qty'];
        }

        $columns[] = ['label' => 'Stok Minimum', 'value' => fn (Product $p) => $p->min_stock, 'type' => 'qty'];
        $columns[] = ['label' => 'Status', 'value' => fn (Product $p) => $p->statusStok()->value];

        if ($this->withCost) {
            $columns[] = ['label' => 'Harga Beli', 'value' => fn (Product $p) => $p->purchase_price, 'type' => 'money'];
            $columns[] = ['label' => 'Nilai Persediaan', 'value' => fn (Product $p) => $this->value($p), 'type' => 'money'];
        }

        return $columns;
    }

    /** Nilai persediaan: stok (gudang terpilih, atau total) × harga beli. Stok minus tidak bernilai. */
    public function value(Product $product): float
    {
        $quantity = isset($this->filters['gudang']) ? $product->stockIn((int) $this->filters['gudang']) : (float) $product->stock;

        return round(max($quantity, 0) * (float) $product->purchase_price, 2);
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Gudang' => isset($f['gudang']) ? $this->warehouses()->first()?->name : 'Semua gudang aktif',
            'Pencarian' => $f['cari'] ?? null,
            'Kategori' => isset($f['kategori']) ? Category::find($f['kategori'])?->name : null,
            'Status stok' => isset($f['stok']) ? ucfirst($f['stok']) : null,
        ]);
    }
}
