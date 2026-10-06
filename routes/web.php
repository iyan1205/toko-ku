<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // ---- Produk: hak akses dicek lewat ProductPolicy (Kasir boleh melihat, Admin mengelola) ----
    // Route statis harus di atas resource agar "export"/"impor" tidak ditangkap sebagai {product}.
    Route::get('produk/export', [ProductController::class, 'export'])->name('products.export');
    Route::get('produk/template-impor', [ProductController::class, 'template'])->name('products.template');
    Route::get('produk/impor', [ProductController::class, 'importForm'])->name('products.import');
    Route::post('produk/impor', [ProductController::class, 'import'])->name('products.import.store');
    Route::resource('produk', ProductController::class)->parameters(['produk' => 'product'])->names('products');

    // ---- Master data: izin dicek lewat middleware ----
    Route::middleware('can:manage-categories')->group(function () {
        Route::resource('kategori', CategoryController::class)
            ->parameters(['kategori' => 'category'])->names('categories')
            ->only(['index', 'store', 'update', 'destroy']);
    });

    Route::middleware('can:manage-products')->group(function () {
        Route::resource('satuan', UnitController::class)
            ->parameters(['satuan' => 'unit'])->names('units')
            ->only(['index', 'store', 'update', 'destroy']);
    });

    Route::middleware('can:manage-suppliers')->group(function () {
        Route::resource('supplier', SupplierController::class)->names('suppliers');
    });

    Route::middleware('can:manage-customers')->group(function () {
        Route::resource('pelanggan', CustomerController::class)
            ->parameters(['pelanggan' => 'customer'])->names('customers');
    });

    // ---- Inventory (Tahap 3) ----
    Route::get('stok', [StockController::class, 'index'])->middleware('can:view-stock')->name('stock.index');

    Route::middleware('can:manage-stock')->group(function () {
        Route::get('stok/mutasi', [StockController::class, 'movements'])->name('stock.movements');
        Route::get('stok/penyesuaian', [StockController::class, 'createAdjustment'])->name('stock.adjust.create');
        Route::post('stok/penyesuaian', [StockController::class, 'storeAdjustment'])->name('stock.adjust.store');
    });

    Route::middleware('can:stock-opname')->group(function () {
        Route::resource('stok-opname', StockOpnameController::class)
            ->parameters(['stok-opname' => 'stockOpname'])
            ->names('stock-opnames')
            ->only(['index', 'create', 'store', 'show']);
    });

    // ---- Pembelian (Tahap 4). Route statis di atas {purchase}. Pembelian tidak punya edit/hapus: dibatalkan (void). ----
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

    // ---- Pengguna (Tahap 1) ----
    Route::middleware('can:manage-users')->group(function () {
        Route::resource('pengguna', UserController::class)
            ->parameters(['pengguna' => 'user'])
            ->names('users')
            ->except('show');

        Route::patch('pengguna/{user}/status', [UserController::class, 'toggleStatus'])->name('users.toggle');
    });
});

require __DIR__ . '/auth.php';
