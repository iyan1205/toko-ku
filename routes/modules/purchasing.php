<?php

/*
| Pembelian: supplier dan faktur pembelian.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-suppliers')->group(function () {
    Route::resource('supplier', SupplierController::class)->names('suppliers');
});

// Route statis di atas {purchase}. Pembelian tidak punya edit/hapus: dibatalkan (void).
Route::middleware('can:create-purchase')->group(function () {
    Route::get('pembelian/cari-produk', [PurchaseController::class, 'searchProducts'])->name('purchases.products');
    Route::get('pembelian/buat', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::post('pembelian', [PurchaseController::class, 'store'])->name('purchases.store');
});

Route::middleware('can:view-purchases')->group(function () {
    Route::get('pembelian', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('pembelian/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
});

Route::post('pembelian/{purchase}/batal', [PurchaseController::class, 'void'])
    ->whereNumber('purchase')->middleware('can:void-purchase')->name('purchases.void');
