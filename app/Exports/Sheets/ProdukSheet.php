<?php

namespace App\Exports\Sheets;

use App\Reports\LaporanPenjualan;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
/**
 * Lembar "Produk": produk terlaris menurut omzet pada rentang tanggal.
 */
class ProdukSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private readonly LaporanPenjualan $laporan,
        private readonly int $batas = 50,
    ) {
    }

    public function title(): string
    {
        return 'Produk';
    }

    public function headings(): array
    {
        return ['SKU', 'Nama Produk', 'Satuan', 'Jumlah Terjual', 'Omzet', 'Laba Kotor'];
    }

    public function collection(): Enumerable
    {
        return $this->laporan->produkTeratas($this->batas);
    }

    /** @param array<string, mixed> $baris */
    public function map($baris): array
    {
        return [
            $baris['sku'],
            $baris['nama'],
            $baris['satuan'],
            $baris['jumlah'],
            $baris['omzet'],
            $baris['laba'],
        ];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return ['D' => '#,##0.###', 'E' => '#,##0', 'F' => '#,##0'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}