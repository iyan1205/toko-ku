<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    /** @param array<string, mixed> $filter filter yang sama dengan halaman daftar produk */
    public function __construct(private readonly array $filter = [])
    {
    }

    public function query(): Builder
    {
        return Product::query()->with(['category', 'unit'])->filter($this->filter)->orderBy('name');
    }

    public function headings(): array
    {
        return ['SKU', 'Barcode', 'Nama Produk', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Jual', 'Stok', 'Stok Minimum', 'Diskon (%)', 'Status', 'Deskripsi'];
    }

    /** @param Product $p */
    public function map($p): array
    {
        return [
            $p->sku,
            $p->barcode !== null ? (string) $p->barcode : null,
            $p->name,
            $p->category?->name,
            $p->unit?->name,
            (float) $p->purchase_price,
            (float) $p->selling_price,
            (float) $p->stock,
            (float) $p->min_stock,
            (float) $p->discount_percent,
            $p->is_active ? 'Aktif' : 'Nonaktif',
            $p->description,
        ];
    }
}
