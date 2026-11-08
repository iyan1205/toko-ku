<?php

/*
| Inventori: produk, kategori, satuan, stok, stok opname, dan resep (BOM).
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

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

// ---- Stok ----
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

// ---- Bill of Materials (resep) ----
Route::middleware('can:manage-products')->group(function () {
    Route::resource('recipe', RecipeController::class)->names('recipes');
});
