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
 * Lembar "Metode Pembayaran": sebaran omzet per metode, termasuk porsi piutang.
 */
class MetodeSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private readonly LaporanPenjualan $laporan)
    {
    }

    public function title(): string
    {
        return 'Metode Pembayaran';
    }

    public function headings(): array
    {
        return ['Metode', 'Transaksi', 'Omzet', 'Sisa Piutang'];
    }

    public function collection(): Enumerable
    {
        return $this->laporan->perMetode();
    }

    /** @param array<string, mixed> $baris */
    public function map($baris): array
    {
        return [
            $baris['metode']?->label() ?? 'Tidak dikenal',
            $baris['jumlah'],
            $baris['omzet'],
            $baris['piutang'],
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