<?php

namespace App\Exports;

use App\Exports\Sheets\HarianSheet;
use App\Exports\Sheets\KasirSheet;
use App\Exports\Sheets\MetodeSheet;
use App\Exports\Sheets\ProdukSheet;
use App\Exports\Sheets\RingkasanSheet;
use App\Reports\LaporanPenjualan;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Buku kerja laporan penjualan: satu lembar per sudut pandang (ringkasan, harian,
 * produk, kasir, metode pembayaran) supaya bisa dianalisis lebih lanjut di Excel.
 *
 * WithMultipleSheets tidak mewarisi Export, jadi Export di-implement eksplisit
 * agar bisa dipakai langsung oleh Excel::download().
 */
class LaporanPenjualanExport implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(private readonly LaporanPenjualan $laporan)
    {
    }

    /** @return array<int, object> */
    public function sheets(): array
    {
        return [
            new RingkasanSheet($this->laporan),
            new HarianSheet($this->laporan),
            new ProdukSheet($this->laporan),
            new KasirSheet($this->laporan),
            new MetodeSheet($this->laporan),
        ];
    }
}
