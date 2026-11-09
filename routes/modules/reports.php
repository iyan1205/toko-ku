<?php

/*
| Laporan. Ekspor Excel memakai filter periode yang sama dengan halaman laporan.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\LaporanController;
use Illuminate\Support\Facades\Route;

Route::get('laporan', [LaporanController::class, 'index'])->middleware('permission:reports.sales.view')->name('reports.index');
Route::get('laporan/penjualan/ekspor', [LaporanController::class, 'export'])->middleware('permission:reports.sales.export')->name('reports.sales.export');
