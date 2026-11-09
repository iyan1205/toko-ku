<?php

/*
| Penjualan: Kasir (POS), riwayat penjualan, struk, dan pelanggan.
| Di-include dari routes/web.php di dalam grup middleware auth.
| Setiap route dijaga izin "{modul}.{fitur}.{aksi}" (config/permission-matrix.php).
*/

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HeldSaleController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StrukController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:pos.transactions.create')->group(function () {
    Route::get('kasir', [PosController::class, 'index'])->name('pos.index');
    Route::get('kasir/cari-produk', [PosController::class, 'searchProducts'])->name('pos.products');
    Route::get('kasir/cari-pelanggan', [PosController::class, 'searchCustomers'])->name('pos.customers');

    Route::get('kasir/tahan', [HeldSaleController::class, 'index'])->name('pos.hold.index');
    Route::post('kasir/tahan', [HeldSaleController::class, 'store'])->name('pos.hold.store');
    Route::get('kasir/tahan/{heldSale}', [HeldSaleController::class, 'show'])->name('pos.hold.show');
    Route::delete('kasir/tahan/{heldSale}', [HeldSaleController::class, 'destroy'])->name('pos.hold.destroy');

    Route::post('penjualan', [SaleController::class, 'store'])->name('sales.store');
});

// Lihat semua (view) atau milik sendiri (view-own). Pembatasan "milik sendiri" dicek SalePolicy di controller.
Route::middleware('permission:pos.transactions.view|pos.transactions.view-own')->group(function () {
    Route::get('penjualan', [SaleController::class, 'index'])->name('sales.index');
    Route::get('penjualan/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');
});

// Struk thermal (halaman ukuran roll) dan teks struk untuk printer Bluetooth (RawBT).
// Selain izin cetak, controller juga memeriksa SalePolicy (kasir hanya struk miliknya).
Route::middleware('permission:pos.transactions.print')->group(function () {
    Route::get('penjualan/{sale}/struk', [ReceiptController::class, 'show'])->whereNumber('sale')->name('sales.receipt');
    Route::get('penjualan/{sale}/struk-teks', [StrukController::class, 'teks'])->whereNumber('sale')->name('sales.receipt.text');
});

Route::post('penjualan/{sale}/batal', [SaleController::class, 'void'])
    ->whereNumber('sale')->middleware('permission:pos.transactions.void')->name('sales.void');

Route::resource('pelanggan', CustomerController::class)
    ->parameters(['pelanggan' => 'customer'])->names('customers')
    ->middlewareFor(['index', 'show'], 'permission:pos.customers.view')
    ->middlewareFor(['create', 'store'], 'permission:pos.customers.create')
    ->middlewareFor(['edit', 'update'], 'permission:pos.customers.update')
    ->middlewareFor('destroy', 'permission:pos.customers.delete');
