<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Templat kosong (hanya judul kolom) agar tidak ada baris contoh yang ikut terimpor. */
class ProductImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return ['SKU', 'Barcode', 'Nama Produk', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Jual', 'Stok Minimum', 'Diskon (%)', 'Status', 'Deskripsi'];
    }
}
