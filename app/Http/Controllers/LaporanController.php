<?php

namespace App\Http\Controllers;

use App\Exports\LaporanPenjualanExport;
use App\Http\Requests\LaporanRequest;
use App\Reports\LaporanPenjualan;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan penjualan. Satu halaman dengan beberapa sudut pandang (ringkasan, harian,
 * produk terlaris, kasir, metode pembayaran) plus ekspor Excel multi-lembar.
 *
 * Izin reports.sales.* dipasang di routes/modules/reports.php lewat middleware 'permission:'.
 */
class LaporanController extends Controller
{
    public function index(LaporanRequest $request): View
    {
        return view('reports.sales', $this->data($request));
    }

    public function export(LaporanRequest $request): BinaryFileResponse
    {
        $laporan = new LaporanPenjualan($request->dari(), $request->sampai());

        return Excel::download(
            new LaporanPenjualanExport($laporan),
            'laporan-penjualan-'.$laporan->dari()->format('Ymd').'-'.$laporan->sampai()->format('Ymd').'.xlsx'
        );
    }

    /** @return array<string, mixed> */
    private function data(LaporanRequest $request): array
    {
        $laporan = new LaporanPenjualan($request->dari(), $request->sampai());

        return [
            'laporan' => $laporan,
            'ringkasan' => $laporan->ringkasan(),
            'harian' => $laporan->harian(),
            'perProduk' => $laporan->produkTeratas(10),
            'perKasir' => $laporan->perKasir(),
            'perMetode' => $laporan->perMetode(),
        ];
    }
}
