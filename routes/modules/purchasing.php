<?php

/*
| Pembelian: supplier dan faktur pembelian.
| Di-include dari routes/web.php di dalam grup middleware auth.
| Setiap route dijaga izin "{modul}.{fitur}.{aksi}" (config/permission-matrix.php).
*/

use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::resource('supplier', SupplierController::class)->names('suppliers')
    ->middlewareFor(['index', 'show'], 'permission:purchasing.suppliers.view')
    ->middlewareFor(['create', 'store'], 'permission:purchasing.suppliers.create')
    ->middlewareFor(['edit', 'update'], 'permission:purchasing.suppliers.update')
    ->middlewareFor('destroy', 'permission:purchasing.suppliers.delete');

// Route statis di atas {purchase}. Pembelian tidak punya edit/hapus: dibatalkan (void).
Route::middleware('permission:purchasing.invoices.create')->group(function () {
    Route::get('pembelian/cari-produk', [PurchaseController::class, 'searchProducts'])->name('purchases.products');
    Route::get('pembelian/buat', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::post('pembelian', [PurchaseController::class, 'store'])->name('purchases.store');
});

Route::middleware('permission:purchasing.invoices.view')->group(function () {
    Route::get('pembelian', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('pembelian/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
});

Route::post('pembelian/{purchase}/batal', [PurchaseController::class, 'void'])
    ->whereNumber('purchase')->middleware('permission:purchasing.invoices.void')->name('purchases.void');
