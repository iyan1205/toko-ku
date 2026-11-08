<?php

/*
| Penjualan: Kasir (POS), riwayat penjualan, struk, dan pelanggan.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HeldSaleController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StrukController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:create-sale')->group(function () {
    Route::get('kasir', [PosController::class, 'index'])->name('pos.index');
    Route::get('kasir/cari-produk', [PosController::class, 'searchProducts'])->name('pos.products');
    Route::get('kasir/cari-pelanggan', [PosController::class, 'searchCustomers'])->name('pos.customers');

    Route::get('kasir/tahan', [HeldSaleController::class, 'index'])->name('pos.hold.index');
    Route::post('kasir/tahan', [HeldSaleController::class, 'store'])->name('pos.hold.store');
    Route::get('kasir/tahan/{heldSale}', [HeldSaleController::class, 'show'])->name('pos.hold.show');
    Route::delete('kasir/tahan/{heldSale}', [HeldSaleController::class, 'destroy'])->name('pos.hold.destroy');

    Route::post('penjualan', [SaleController::class, 'store'])->name('sales.store');
});

// Lihat daftar/detail: dicek via SalePolicy (view-sales = semua, view-own-sales = milik sendiri) di controller,
// bukan middleware 'can:', karena ini OR dua izin dan middleware can: tidak mendukung itu.
Route::get('penjualan', [SaleController::class, 'index'])->name('sales.index');
Route::get('penjualan/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');

// Struk thermal: halaman berdiri sendiri berukuran roll, dicetak dari komputer kasir.
// Izin dicek di controller: SalePolicy (milik sendiri untuk kasir) + print-receipt.
Route::get('penjualan/{sale}/struk', [ReceiptController::class, 'show'])
    ->whereNumber('sale')->name('sales.receipt');

// Teks struk untuk printer Bluetooth lewat HP (RawBT). Dipakai halaman struk.
Route::get('penjualan/{sale}/struk-teks', [StrukController::class, 'teks'])
    ->whereNumber('sale')->name('sales.receipt.text');

Route::post('penjualan/{sale}/batal', [SaleController::class, 'void'])
    ->whereNumber('sale')->middleware('can:void-sale')->name('sales.void');

Route::middleware('can:manage-customers')->group(function () {
    Route::resource('pelanggan', CustomerController::class)
        ->parameters(['pelanggan' => 'customer'])->names('customers');
});
