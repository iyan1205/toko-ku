<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\WarehouseStock;

/**
 * Pencarian produk untuk formulir dokumen (pembelian, PO, transfer): nama, SKU, atau barcode
 * hasil scan. Bila gudang disebut, `stock` = stok di gudang itu; selain itu total semua gudang.
 * Bentuk hasil sama dengan yang dipakai resources/js/dokumen-barang.js.
 */
class ProductSearch
{
    public const LIMIT = 15;

    /**
     * Baris formulir dari old input atau dokumen draft: setiap baris dilengkapi sku, nama, dan satuan
     * produknya. Kolom lain (quantity, unit_price, discount) dibawa apa adanya. Baris dengan produk
     * yang sudah tidak ada dibuang.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function formLines(iterable $rows): array
    {
        $rows = collect($rows)->map(fn ($row) => (array) $row);
        $produk = Product::whereIn('id', $rows->pluck('product_id')->filter())->with('unit:id,name')->get()->keyBy('id');

        return $rows->filter(fn (array $row) => isset($produk[$row['product_id'] ?? null]))
            ->map(fn (array $row) => [
                'product_id' => (int) $row['product_id'],
                'sku' => $produk[$row['product_id']]->sku,
                'name' => $produk[$row['product_id']]->name,
                'unit' => $produk[$row['product_id']]->unit?->name,
                'quantity' => (string) ($row['quantity'] ?? '1'),
                'unit_price' => (string) ($row['unit_price'] ?? $produk[$row['product_id']]->purchase_price),
                'discount' => (string) ($row['discount'] ?? '0'),
            ])->values()->all();
    }

    /**
     * @param  list<int>  $ids  ambil produk tertentu (untuk memuat ulang stok baris yang sudah dipilih)
     * @return list<array{id: int, sku: string, barcode: ?string, name: string, unit: ?string, stock: float, purchase_price: float}>
     */
    public function search(?string $keyword, ?int $warehouseId = null, array $ids = []): array
    {
        $keyword = trim((string) $keyword);

        if ($keyword === '' && $ids === []) {
            return [];
        }

        $products = Product::aktif()->with('unit:id,name')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->cari($keyword)
                ->orderByRaw('CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END', [$keyword, $keyword])
                ->orderBy('name')
                ->limit(self::LIMIT))
            ->get();

        $warehouseStock = $warehouseId === null ? collect() : WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)->whereIn('product_id', $products->modelKeys())
            ->pluck('quantity', 'product_id');

        return $products->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'unit' => $p->unit?->name,
            'stock' => round((float) ($warehouseId === null ? $p->stock : ($warehouseStock[$p->id] ?? 0)), 3),
            'purchase_price' => (float) $p->purchase_price,
        ])->values()->all();
    }
}
