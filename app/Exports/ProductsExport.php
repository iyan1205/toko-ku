<?php

namespace App\Exports;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar produk. Kolomnya sama dengan templat impor produk, jadi berkas Excel hasil ekspor
 * bisa diubah lalu diimpor kembali. Jangan ubah urutan kolom tanpa menyesuaikan ProductsImport.
 */
class ProductsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Daftar Produk';
    }

    protected function filename(): string
    {
        return 'produk-'.now()->format('Ymd');
    }

    protected function baseQuery(): Builder
    {
        return Product::query()->with(['category:id,name', 'unit:id,name'])->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('name');
    }

    protected function sorts(): array
    {
        return [
            'name' => 'name',
            'sku' => 'sku',
            'category' => fn (Builder $q, string $dir) => $q->orderBy(Category::select('name')->whereColumn('categories.id', 'products.category_id'), $dir),
            'purchase_price' => 'purchase_price',
            'selling_price' => 'selling_price',
            'stock' => 'stock',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'SKU', 'value' => fn (Product $p) => $p->sku],
            ['label' => 'Barcode', 'value' => fn (Product $p) => $p->barcode !== null ? (string) $p->barcode : null],
            ['label' => 'Nama Produk', 'value' => fn (Product $p) => $p->name],
            ['label' => 'Kategori', 'value' => fn (Product $p) => $p->category?->name],
            ['label' => 'Satuan', 'value' => fn (Product $p) => $p->unit?->name],
            ['label' => 'Harga Beli', 'value' => fn (Product $p) => $p->purchase_price, 'type' => 'money'],
            ['label' => 'Harga Jual', 'value' => fn (Product $p) => $p->selling_price, 'type' => 'money'],
            ['label' => 'Stok', 'value' => fn (Product $p) => $p->stock, 'type' => 'qty'],
            ['label' => 'Stok Minimum', 'value' => fn (Product $p) => $p->min_stock, 'type' => 'qty'],
            ['label' => 'Diskon (%)', 'value' => fn (Product $p) => $p->discount_percent, 'type' => 'qty'],
            ['label' => 'Status', 'value' => fn (Product $p) => $p->is_active ? 'Aktif' : 'Nonaktif'],
            ['label' => 'Deskripsi', 'value' => fn (Product $p) => $p->description],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            'Kategori' => isset($f['kategori']) ? Category::find($f['kategori'])?->name : null,
            'Stok' => isset($f['stok']) ? ucfirst($f['stok']) : null,
            'Status' => isset($f['status']) ? ucfirst($f['status']) : null,
        ]);
    }
}
