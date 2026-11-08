<?php

/*
| Laporan. Izin view-reports; ekspor Excel memakai filter periode yang sama.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\LaporanController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:view-reports')->group(function () {
    Route::get('laporan', [LaporanController::class, 'index'])->name('reports.index');
    Route::get('laporan/penjualan/ekspor', [LaporanController::class, 'export'])->name('reports.sales.export');
});
