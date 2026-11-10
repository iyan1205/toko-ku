<?php

/*
| Pembelian: supplier, purchase order, penerimaan barang, faktur pembelian, retur, dan hutang supplier.
| Alur: PO (draft → disetujui) → penerimaan barang (boleh bertahap) → stok bertambah + faktur/hutang.
| Pembelian langsung tanpa PO tetap tersedia lewat Faktur Pembelian → Pembelian baru.
|
| Di-include dari routes/web.php di dalam grup middleware auth. Setiap route dijaga izin
| "{modul}.{fitur}.{aksi}" (config/permission-matrix.php). Route statis (ekspor, buat, cari-produk)
| ditulis di atas route ber-parameter agar tidak tertangkap sebagai {id}.
*/

use App\Http\Controllers\Inventory\ProductLookupController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PayableController;
use App\Http\Controllers\Purchasing\PurchaseController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseReturnController;
use App\Http\Controllers\Purchasing\SupplierController;
use Illuminate\Support\Facades\Route;

// ---------------- Supplier ----------------
Route::get('supplier/ekspor', [SupplierController::class, 'export'])->middleware('permission:purchasing.suppliers.export')->name('suppliers.export');

Route::resource('supplier', SupplierController::class)->names('suppliers')
    ->middlewareFor(['index', 'show'], 'permission:purchasing.suppliers.view')
    ->middlewareFor(['create', 'store'], 'permission:purchasing.suppliers.create')
    ->middlewareFor(['edit', 'update'], 'permission:purchasing.suppliers.update')
    ->middlewareFor('destroy', 'permission:purchasing.suppliers.delete');

// ---------------- Purchase order ----------------
Route::get('purchase-order/ekspor', [PurchaseOrderController::class, 'export'])->middleware('permission:purchasing.orders.export')->name('purchase-orders.export');
Route::get('purchase-order/cari-produk', ProductLookupController::class)->middleware('permission:purchasing.orders.create|purchasing.orders.update')->name('purchase-orders.products');

Route::resource('purchase-order', PurchaseOrderController::class)
    ->parameters(['purchase-order' => 'purchaseOrder'])
    ->names('purchase-orders')
    ->middlewareFor(['index', 'show'], 'permission:purchasing.orders.view')
    ->middlewareFor(['create', 'store'], 'permission:purchasing.orders.create')
    ->middlewareFor(['edit', 'update'], 'permission:purchasing.orders.update')
    ->middlewareFor('destroy', 'permission:purchasing.orders.delete');

Route::prefix('purchase-order/{purchaseOrder}')->name('purchase-orders.')->whereNumber('purchaseOrder')->group(function () {
    Route::post('setujui', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchasing.orders.approve')->name('approve');
    Route::post('batal', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchasing.orders.approve')->name('cancel');
    Route::post('tutup', [PurchaseOrderController::class, 'close'])->middleware('permission:purchasing.orders.approve')->name('close');
    Route::get('cetak', [PurchaseOrderController::class, 'print'])->middleware('permission:purchasing.orders.print')->name('print');
});

// ---------------- Penerimaan barang ----------------
Route::get('penerimaan-barang/ekspor', [GoodsReceiptController::class, 'export'])->middleware('permission:purchasing.receipts.export')->name('goods-receipts.export');
Route::get('penerimaan-barang/buat', [GoodsReceiptController::class, 'create'])->middleware('permission:purchasing.receipts.create')->name('goods-receipts.create');
Route::post('purchase-order/{purchaseOrder}/penerimaan', [GoodsReceiptController::class, 'store'])
    ->whereNumber('purchaseOrder')->middleware('permission:purchasing.receipts.create')->name('goods-receipts.store');
Route::get('penerimaan-barang', [GoodsReceiptController::class, 'index'])->middleware('permission:purchasing.receipts.view')->name('goods-receipts.index');
Route::get('penerimaan-barang/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
    ->whereNumber('goodsReceipt')->middleware('permission:purchasing.receipts.view')->name('goods-receipts.show');
Route::post('penerimaan-barang/{goodsReceipt}/batal', [GoodsReceiptController::class, 'void'])
    ->whereNumber('goodsReceipt')->middleware('permission:purchasing.receipts.void')->name('goods-receipts.void');

// ---------------- Faktur pembelian (pembelian langsung dan hasil penerimaan) ----------------
// Faktur tidak punya edit/hapus: dibatalkan (void).
Route::get('pembelian/ekspor', [PurchaseController::class, 'export'])->middleware('permission:purchasing.invoices.export')->name('purchases.export');

Route::middleware('permission:purchasing.invoices.create')->group(function () {
    Route::get('pembelian/cari-produk', ProductLookupController::class)->name('purchases.products');
    Route::get('pembelian/buat', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::post('pembelian', [PurchaseController::class, 'store'])->name('purchases.store');
});

Route::middleware('permission:purchasing.invoices.view')->group(function () {
    Route::get('pembelian', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('pembelian/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
});

Route::post('pembelian/{purchase}/batal', [PurchaseController::class, 'void'])
    ->whereNumber('purchase')->middleware('permission:purchasing.invoices.void')->name('purchases.void');

// ---------------- Retur pembelian ----------------
Route::get('retur-pembelian/ekspor', [PurchaseReturnController::class, 'export'])->middleware('permission:purchasing.returns.export')->name('purchase-returns.export');
Route::get('retur-pembelian/buat', [PurchaseReturnController::class, 'create'])->middleware('permission:purchasing.returns.create')->name('purchase-returns.create');
Route::post('retur-pembelian', [PurchaseReturnController::class, 'store'])->middleware('permission:purchasing.returns.create')->name('purchase-returns.store');
Route::get('retur-pembelian', [PurchaseReturnController::class, 'index'])->middleware('permission:purchasing.returns.view')->name('purchase-returns.index');

Route::prefix('retur-pembelian/{purchaseReturn}')->name('purchase-returns.')->whereNumber('purchaseReturn')->group(function () {
    Route::get('/', [PurchaseReturnController::class, 'show'])->middleware('permission:purchasing.returns.view')->name('show');
    Route::post('setujui', [PurchaseReturnController::class, 'approve'])->middleware('permission:purchasing.returns.approve')->name('approve');
    Route::delete('/', [PurchaseReturnController::class, 'destroy'])->middleware('permission:purchasing.returns.delete')->name('destroy');
});

// ---------------- Hutang supplier ----------------
Route::get('hutang-supplier/ekspor', [PayableController::class, 'export'])->middleware('permission:purchasing.payables.export')->name('payables.export');
Route::get('hutang-supplier', [PayableController::class, 'index'])->middleware('permission:purchasing.payables.view')->name('payables.index');
Route::post('pembelian/{purchase}/bayar', [PayableController::class, 'store'])
    ->whereNumber('purchase')->middleware('permission:purchasing.payables.create')->name('payables.store');
