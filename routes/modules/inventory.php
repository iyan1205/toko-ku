<?php

/*
| Inventori: produk, kategori, satuan, stok & kartu stok, transfer stok, stok opname,
| gudang/lokasi, dan resep (BOM).
| Di-include dari routes/web.php di dalam grup middleware auth.
| Setiap route dijaga izin "{modul}.{fitur}.{aksi}" (config/permission-matrix.php).
| Route statis (ekspor, cari-produk) ditulis di atas route ber-parameter agar tidak tertangkap.
*/

use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\ProductLookupController;
use App\Http\Controllers\Inventory\RecipeController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockOpnameController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\UnitController;
use App\Http\Controllers\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;

// ---- Produk ----
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
Route::get('kategori/ekspor', [CategoryController::class, 'export'])->middleware('permission:inventory.categories.export')->name('categories.export');
Route::resource('kategori', CategoryController::class)
    ->parameters(['kategori' => 'category'])->names('categories')
    ->only(['index', 'store', 'update', 'destroy'])
    ->middlewareFor('index', 'permission:inventory.categories.view')
    ->middlewareFor('store', 'permission:inventory.categories.create')
    ->middlewareFor('update', 'permission:inventory.categories.update')
    ->middlewareFor('destroy', 'permission:inventory.categories.delete');

Route::get('satuan/ekspor', [UnitController::class, 'export'])->middleware('permission:inventory.units.export')->name('units.export');
Route::resource('satuan', UnitController::class)
    ->parameters(['satuan' => 'unit'])->names('units')
    ->only(['index', 'store', 'update', 'destroy'])
    ->middlewareFor('index', 'permission:inventory.units.view')
    ->middlewareFor('store', 'permission:inventory.units.create')
    ->middlewareFor('update', 'permission:inventory.units.update')
    ->middlewareFor('destroy', 'permission:inventory.units.delete');

// ---- Stok & kartu stok ----
Route::get('stok', [StockController::class, 'index'])->middleware('permission:inventory.stock.view')->name('stock.index');
Route::get('stok/ekspor', [StockController::class, 'export'])->middleware('permission:inventory.stock.export')->name('stock.export');
Route::get('stok/mutasi', [StockController::class, 'movements'])->middleware('permission:inventory.stock-card.view')->name('stock.movements');
Route::get('stok/mutasi/ekspor', [StockController::class, 'exportMovements'])->middleware('permission:inventory.stock-card.export')->name('stock.movements.export');
Route::middleware('permission:inventory.adjustments.create')->group(function () {
    Route::get('stok/penyesuaian', [StockController::class, 'createAdjustment'])->name('stock.adjust.create');
    Route::post('stok/penyesuaian', [StockController::class, 'storeAdjustment'])->name('stock.adjust.store');
});

// ---- Transfer stok antar gudang (langsung selesai; dibatalkan, tidak diedit) ----
Route::get('transfer-stok/ekspor', [StockTransferController::class, 'export'])->middleware('permission:inventory.transfers.export')->name('stock-transfers.export');
Route::get('transfer-stok/cari-produk', ProductLookupController::class)->middleware('permission:inventory.transfers.create')->name('stock-transfers.products');
Route::get('transfer-stok/{stockTransfer}/surat-jalan', [StockTransferController::class, 'print'])->middleware('permission:inventory.transfers.print')->name('stock-transfers.print');
Route::post('transfer-stok/{stockTransfer}/batal', [StockTransferController::class, 'void'])->middleware('permission:inventory.transfers.void')->name('stock-transfers.void');
Route::resource('transfer-stok', StockTransferController::class)
    ->parameters(['transfer-stok' => 'stockTransfer'])->names('stock-transfers')
    ->only(['index', 'create', 'store', 'show'])
    ->middlewareFor(['index', 'show'], 'permission:inventory.transfers.view')
    ->middlewareFor(['create', 'store'], 'permission:inventory.transfers.create');

// ---- Stok opname per gudang: draft (create) → selesai (approve) ----
Route::get('stok-opname/ekspor', [StockOpnameController::class, 'export'])->middleware('permission:inventory.opnames.export')->name('stock-opnames.export');
Route::post('stok-opname/{stockOpname}/selesaikan', [StockOpnameController::class, 'finalize'])->middleware('permission:inventory.opnames.approve')->name('stock-opnames.finalize');
Route::resource('stok-opname', StockOpnameController::class)
    ->parameters(['stok-opname' => 'stockOpname'])
    ->names('stock-opnames')
    ->middlewareFor(['index', 'show'], 'permission:inventory.opnames.view')
    ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:inventory.opnames.create');

// ---- Gudang/lokasi ----
Route::get('gudang/ekspor', [WarehouseController::class, 'export'])->middleware('permission:inventory.warehouses.export')->name('warehouses.export');
Route::resource('gudang', WarehouseController::class)
    ->parameters(['gudang' => 'warehouse'])->names('warehouses')
    ->except('show')
    ->middlewareFor('index', 'permission:inventory.warehouses.view')
    ->middlewareFor(['create', 'store'], 'permission:inventory.warehouses.create')
    ->middlewareFor(['edit', 'update'], 'permission:inventory.warehouses.update')
    ->middlewareFor('destroy', 'permission:inventory.warehouses.delete');

// ---- Bill of Materials (resep) ----
Route::get('recipe/ekspor', [RecipeController::class, 'export'])->middleware('permission:inventory.recipes.export')->name('recipes.export');
Route::resource('recipe', RecipeController::class)->names('recipes')
    ->middlewareFor(['index', 'show'], 'permission:inventory.recipes.view')
    ->middlewareFor(['create', 'store'], 'permission:inventory.recipes.create')
    ->middlewareFor(['edit', 'update'], 'permission:inventory.recipes.update')
    ->middlewareFor('destroy', 'permission:inventory.recipes.delete');
