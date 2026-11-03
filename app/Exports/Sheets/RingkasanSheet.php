<?php

namespace App\Exports\Sheets;

use App\Reports\LaporanPenjualan;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lembar "Ringkasan": satu blok angka kunci untuk rentang tanggal laporan.
 */
class RingkasanSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly LaporanPenjualan $laporan)
    {
    }

    public function title(): string
    {
        return 'Ringkasan';
    }

    public function headings(): array
    {
        return ['Periode', 'Keterangan', 'Nilai'];
    }

    public function array(): array
    {
        $r = $this->laporan->ringkasan();

        return [
            [$this->periode(), 'Jumlah transaksi', $r['jumlah_transaksi']],
            [$this->periode(), 'Jumlah barang terjual', $r['jumlah_item']],
            [$this->periode(), 'Omzet (penjualan bersih)', $r['omzet']],
            [$this->periode(), 'HPP (harga pokok penjualan)', $r['hpp']],
            [$this->periode(), 'Laba kotor', $r['laba']],
            [$this->periode(), 'Margin laba (%)', $r['margin']],
            [$this->periode(), 'Rata-rata per transaksi', $r['rata_rata']],
            [$this->periode(), 'Diskon item', $r['diskon_item']],
            [$this->periode(), 'Diskon transaksi', $r['diskon_transaksi']],
            [$this->periode(), 'Sisa piutang', $r['piutang']],
        ];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return ['C' => '#,##0'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    private function periode(): string
    {
        return $this->laporan->dari()->format('d-m-Y') . ' s/d ' . $this->laporan->sampai()->format('d-m-Y');
    }
}
