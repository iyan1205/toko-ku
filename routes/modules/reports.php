<?php

/*
| Laporan. Ekspor Excel memakai filter periode yang sama dengan halaman laporan.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\LaporanController;
use App\Http\Controllers\Reports\StockReportController;
use Illuminate\Support\Facades\Route;

Route::get('laporan', [LaporanController::class, 'index'])->middleware('permission:reports.sales.view')->name('reports.index');
Route::get('laporan/penjualan/ekspor', [LaporanController::class, 'export'])->middleware('permission:reports.sales.export')->name('reports.sales.export');

// ---- Laporan stok: nilai persediaan per tanggal, perputaran, barang lambat & mati, kartu stok per batch ----
Route::middleware('permission:reports.stock.view')->group(function () {
    Route::get('laporan/stok/nilai-persediaan', [StockReportController::class, 'valuation'])->name('reports.stock.valuation');
    Route::get('laporan/stok/perputaran', [StockReportController::class, 'turnover'])->name('reports.stock.turnover');
    Route::get('laporan/stok/lambat-mati', [StockReportController::class, 'slowMoving'])->name('reports.stock.slow-moving');
    Route::get('laporan/stok/kartu-batch', [StockReportController::class, 'batchCard'])->name('reports.stock.batch-card');
});
Route::get('laporan/stok/{report}/ekspor', [StockReportController::class, 'export'])
    ->whereIn('report', ['nilai-persediaan', 'perputaran', 'lambat-mati', 'kartu-batch'])
    ->middleware('permission:reports.stock.export')->name('reports.stock.export');
