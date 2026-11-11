<?php

/*
| Penjualan: Kasir (POS), riwayat transaksi, struk, sesi kasir, retur penjualan, pelanggan,
| dan piutang pelanggan. Di-include dari routes/web.php di dalam grup middleware auth.
| Setiap route dijaga izin "{modul}.{fitur}.{aksi}" (config/permission-matrix.php).
| Hak per dokumen (milik sendiri atau semua) dicek Policy di controller.
*/

use App\Http\Controllers\Accounting\InvoiceController;
use App\Http\Controllers\Accounting\PartyLedgerController;
use App\Http\Controllers\Accounting\SettlementController;
use App\Http\Controllers\Pos\CashSessionController;
use App\Http\Controllers\Pos\CustomerController;
use App\Http\Controllers\Pos\HeldSaleController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\ReceiptController;
use App\Http\Controllers\Pos\ReceivableController;
use App\Http\Controllers\Pos\SaleController;
use App\Http\Controllers\Pos\SaleReturnController;
use App\Http\Controllers\Pos\StrukController;
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

// Sesi kasir. Kasir: sesi miliknya sendiri. pos.sessions.approve: semua sesi dan verifikasi setoran.
Route::prefix('kasir/sesi')->name('pos.sessions.')->controller(CashSessionController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:pos.sessions.view|pos.sessions.create|pos.sessions.approve')->name('index');
    Route::get('ekspor', 'export')->middleware('permission:pos.sessions.export')->name('export');
    Route::post('/', 'store')->middleware('permission:pos.sessions.create')->name('store');
    Route::get('{cashSession}', 'show')->whereNumber('cashSession')
        ->middleware('permission:pos.sessions.view|pos.sessions.create|pos.sessions.approve')->name('show');
    Route::post('{cashSession}/tutup', 'close')->whereNumber('cashSession')
        ->middleware('permission:pos.sessions.create|pos.sessions.approve')->name('close');
    Route::post('{cashSession}/verifikasi', 'verify')->whereNumber('cashSession')
        ->middleware('permission:pos.sessions.approve')->name('verify');
});

// Lihat semua (view) atau milik sendiri (view-own). Pembatasan "milik sendiri" dicek SalePolicy di controller.
Route::middleware('permission:pos.transactions.view|pos.transactions.view-own')->group(function () {
    Route::get('penjualan', [SaleController::class, 'index'])->name('sales.index');
    Route::get('penjualan/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');
});
Route::get('penjualan/ekspor', [SaleController::class, 'export'])->middleware('permission:pos.transactions.export')->name('sales.export');

// Struk thermal (halaman ukuran roll) dan teks struk untuk printer Bluetooth (RawBT).
// Selain izin cetak, controller juga memeriksa SalePolicy (kasir hanya struk miliknya).
Route::middleware('permission:pos.transactions.print')->group(function () {
    Route::get('penjualan/{sale}/struk', [ReceiptController::class, 'show'])->whereNumber('sale')->name('sales.receipt');
    Route::get('penjualan/{sale}/struk-teks', [StrukController::class, 'teks'])->whereNumber('sale')->name('sales.receipt.text');
});

Route::post('penjualan/{sale}/batal', [SaleController::class, 'void'])
    ->whereNumber('sale')->middleware('permission:pos.transactions.void')->name('sales.void');

// Retur penjualan: diajukan (draft), lalu disetujui pemegang pos.returns.approve.
Route::prefix('retur-penjualan')->name('sale-returns.')->controller(SaleReturnController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:pos.returns.view|pos.returns.create|pos.returns.approve')->name('index');
    Route::get('ekspor', 'export')->middleware('permission:pos.returns.export')->name('export');
    Route::get('buat', 'create')->middleware('permission:pos.returns.create')->name('create');
    Route::post('/', 'store')->middleware('permission:pos.returns.create')->name('store');
    Route::get('{saleReturn}', 'show')->whereNumber('saleReturn')
        ->middleware('permission:pos.returns.view|pos.returns.create|pos.returns.approve')->name('show');
    Route::post('{saleReturn}/setujui', 'approve')->whereNumber('saleReturn')
        ->middleware('permission:pos.returns.approve')->name('approve');
    Route::delete('{saleReturn}', 'destroy')->whereNumber('saleReturn')
        ->middleware('permission:pos.returns.create|pos.returns.approve')->name('destroy');
});

// Piutang pelanggan dan pelunasannya.
Route::get('piutang', [ReceivableController::class, 'index'])->middleware('permission:pos.receivables.view')->name('receivables.index');
Route::get('piutang/ekspor', [ReceivableController::class, 'export'])->middleware('permission:pos.receivables.export')->name('receivables.export');
Route::post('penjualan/{sale}/pelunasan', [ReceivableController::class, 'store'])
    ->whereNumber('sale')->middleware('permission:pos.receivables.create')->name('receivables.store');

Route::get('pelanggan/ekspor', [CustomerController::class, 'export'])->middleware('permission:pos.customers.export')->name('customers.export');
Route::resource('pelanggan', CustomerController::class)
    ->parameters(['pelanggan' => 'customer'])->names('customers')
    ->middlewareFor(['index', 'show'], 'permission:pos.customers.view')
    ->middlewareFor(['create', 'store'], 'permission:pos.customers.create')
    ->middlewareFor(['edit', 'update'], 'permission:pos.customers.update')
    ->middlewareFor('destroy', 'permission:pos.customers.delete');

// ---------------- Piutang: faktur penjualan manual, pelunasan gabungan, umur & kartu (Fase 10) ----------------
Route::prefix('faktur-penjualan')->name('sales-invoices.')->group(function () {
    Route::get('/', [InvoiceController::class, 'index'])->middleware('permission:pos.invoices.view')->name('index');
    Route::get('ekspor', [InvoiceController::class, 'export'])->middleware('permission:pos.invoices.export')->name('export');
    Route::middleware('permission:pos.invoices.create')->group(function () {
        Route::get('buat', [InvoiceController::class, 'create'])->name('create');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('{invoice}/ubah', [InvoiceController::class, 'edit'])->whereNumber('invoice')->name('edit');
        Route::put('{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice')->name('update');
        Route::post('{invoice}/posting', [InvoiceController::class, 'post'])->whereNumber('invoice')->name('post');
        Route::delete('{invoice}', [InvoiceController::class, 'destroy'])->whereNumber('invoice')->name('destroy');
    });
    Route::get('{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->middleware('permission:pos.invoices.view')->name('show');
    Route::post('{invoice}/batal', [InvoiceController::class, 'cancel'])->whereNumber('invoice')->middleware('permission:pos.invoices.create|pos.invoices.void')->name('cancel');
});

Route::prefix('piutang/pelunasan')->name('receivable-settlements.')->group(function () {
    Route::get('/', [SettlementController::class, 'index'])->middleware('permission:pos.receivables.view')->name('index');
    Route::get('buat', [SettlementController::class, 'create'])->middleware('permission:pos.receivables.create')->name('create');
    Route::post('/', [SettlementController::class, 'store'])->middleware('permission:pos.receivables.create')->name('store');
    Route::get('{settlement}', [SettlementController::class, 'show'])->whereNumber('settlement')->middleware('permission:pos.receivables.view')->name('show');
    Route::post('{settlement}/batal', [SettlementController::class, 'cancel'])->whereNumber('settlement')->middleware('permission:pos.receivables.void')->name('cancel');
});

Route::middleware('permission:pos.receivables.view')->name('receivable-reports.')->group(function () {
    Route::get('piutang/umur', [PartyLedgerController::class, 'aging'])->name('aging');
    Route::get('piutang/kartu', [PartyLedgerController::class, 'card'])->name('card');
});
