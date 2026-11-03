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
 * Lembar "Kasir": kinerja tiap kasir pada rentang tanggal.
 */
class KasirSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private readonly LaporanPenjualan $laporan)
    {
    }

    public function title(): string
    {
        return 'Kasir';
    }

    public function headings(): array
    {
        return ['Kasir', 'Transaksi', 'Omzet', 'Laba Kotor'];
    }

    public function collection(): Enumerable
    {
        return $this->laporan->perKasir();
    }

    /** @param array<string, mixed> $baris */
    public function map($baris): array
    {
        return [
            $baris['nama'],
            $baris['jumlah'],
            $baris['omzet'],
            $baris['laba'],
        ];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return ['C' => '#,##0', 'D' => '#,##0'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}