<?php

/*
| Inventori: produk, kategori, satuan, stok, stok opname, dan resep (BOM).
| Di-include dari routes/web.php di dalam grup middleware auth.
| Setiap route dijaga izin "{modul}.{fitur}.{aksi}" (config/permission-matrix.php).
*/

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

// ---- Produk. Route statis harus di atas resource agar "export"/"impor" tidak ditangkap sebagai {product}. ----
Route::get('produk/export', [ProductController::class, 'export'])->middleware('permission:inventory.products.export')->name('products.export');
Route::middleware('permission:inventory.products.import')->group(function () {
    Route::get('produk/template-impor', [ProductController::class, 'template'])->name('products.template');
    Route::get('produk/impor', [ProductController::class, 'importForm'])->name('products.import');
    Route::post('produk/impor', [ProductController::class, 'import'])->name('products.import.store');
});
Route::resource('produk', ProductController::class)->parameters(['produk' => 'product'])->names('products')
    ->middlewareFor(['index', 'show'], 'permission:inventory.products.view')
    ->middlewareFor(['create', 'store'], 'permission:inventory.products.create')
    ->middlewareFor(['edit', 'update'], 'permission:inventory.products.update')
    ->middlewareFor('destroy', 'permission:inventory.products.delete');

// ---- Kategori & satuan ----
Route::resource('kategori', CategoryController::class)
    ->parameters(['kategori' => 'category'])->names('categories')
    ->only(['index', 'store', 'update', 'destroy'])
    ->middlewareFor('index', 'permission:inventory.categories.view')
    ->middlewareFor('store', 'permission:inventory.categories.create')
    ->middlewareFor('update', 'permission:inventory.categories.update')
    ->middlewareFor('destroy', 'permission:inventory.categories.delete');

Route::resource('satuan', UnitController::class)
    ->parameters(['satuan' => 'unit'])->names('units')
    ->only(['index', 'store', 'update', 'destroy'])
    ->middlewareFor('index', 'permission:inventory.units.view')
    ->middlewareFor('store', 'permission:inventory.units.create')
    ->middlewareFor('update', 'permission:inventory.units.update')
    ->middlewareFor('destroy', 'permission:inventory.units.delete');

// ---- Stok ----
Route::get('stok', [StockController::class, 'index'])->middleware('permission:inventory.stock.view')->name('stock.index');
Route::get('stok/mutasi', [StockController::class, 'movements'])->middleware('permission:inventory.stock-card.view')->name('stock.movements');
Route::middleware('permission:inventory.adjustments.create')->group(function () {
    Route::get('stok/penyesuaian', [StockController::class, 'createAdjustment'])->name('stock.adjust.create');
    Route::post('stok/penyesuaian', [StockController::class, 'storeAdjustment'])->name('stock.adjust.store');
});

Route::resource('stok-opname', StockOpnameController::class)
    ->parameters(['stok-opname' => 'stockOpname'])
    ->names('stock-opnames')
    ->only(['index', 'create', 'store', 'show'])
    ->middlewareFor(['index', 'show'], 'permission:inventory.opnames.view')
    ->middlewareFor(['create', 'store'], 'permission:inventory.opnames.create');

// ---- Bill of Materials (resep) ----
Route::resource('recipe', RecipeController::class)->names('recipes')
    ->middlewareFor(['index', 'show'], 'permission:inventory.recipes.view')
    ->middlewareFor(['create', 'store'], 'permission:inventory.recipes.create')
    ->middlewareFor(['edit', 'update'], 'permission:inventory.recipes.update')
    ->middlewareFor('destroy', 'permission:inventory.recipes.delete');
