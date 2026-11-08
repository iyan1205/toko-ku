<?php

use App\Http\Controllers\AbsensiController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CutiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HeldSaleController;
use App\Http\Controllers\JadwalController;
use App\Http\Controllers\JenisCutiController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\StrukController;
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

    // ---- Penjualan / Kasir (Tahap 5) ----
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

    // ---- Bill of Materials (resep) ----
    Route::middleware('can:manage-products')->group(function () {
        Route::resource('recipe', RecipeController::class)->names('recipes');
    });

    // ---- Laporan (Tahap 6): izin view-reports. Ekspor Excel memakai filter periode yang sama. ----
    Route::middleware('can:view-reports')->group(function () {
        Route::get('laporan', [LaporanController::class, 'index'])->name('reports.index');
        Route::get('laporan/penjualan/ekspor', [LaporanController::class, 'export'])->name('reports.sales.export');
    });

    // ---- Absensi pegawai ----
    // Daftar: dicek via AbsensiPolicy (manage-attendance = semua pegawai, record-attendance = milik sendiri).
    Route::get('absensi', [AbsensiController::class, 'index'])->name('attendances.index');

    Route::middleware('can:record-attendance')->group(function () {
        Route::post('absensi/masuk', [AbsensiController::class, 'clockIn'])->name('attendances.clock-in');
        Route::post('absensi/pulang', [AbsensiController::class, 'clockOut'])->name('attendances.clock-out');
    });

    // Catatan manual admin: izin/sakit/alpa dan koreksi jam (mis. lupa absen pulang).
    Route::middleware('can:manage-attendance')->group(function () {
        Route::resource('absensi', AbsensiController::class)
            ->parameters(['absensi' => 'absensi'])
            ->names('attendances')
            ->only(['create', 'store', 'edit', 'update', 'destroy']);
    });

    // ---- Jadwal kerja: semua pegawai melihat (JadwalPolicy), manage-schedules mengisi jadwal dan shift ----
    Route::get('jadwal', [JadwalController::class, 'index'])->name('schedules.index');

    Route::middleware('can:manage-schedules')->group(function () {
        Route::put('jadwal', [JadwalController::class, 'update'])->name('schedules.update');
        Route::post('jadwal/salin', [JadwalController::class, 'copy'])->name('schedules.copy');

        Route::resource('shift', ShiftController::class)
            ->names('shifts')
            ->only(['index', 'store', 'update', 'destroy']);
    });

    // ---- Cuti: pengajuan dan persetujuan bertingkat (CutiPolicy) ----
    Route::resource('cuti', CutiController::class)
        ->parameters(['cuti' => 'cuti'])
        ->names('leaves')
        ->only(['index', 'create', 'store', 'show']);
    Route::post('cuti/{cuti}/setujui', [CutiController::class, 'approve'])->name('leaves.approve');
    Route::post('cuti/{cuti}/tolak', [CutiController::class, 'reject'])->name('leaves.reject');
    Route::post('cuti/{cuti}/batal', [CutiController::class, 'cancel'])->name('leaves.cancel');

    // Jenis cuti, kuota, dan alur persetujuannya. Hanya Super Admin (manage-leave-types).
    Route::middleware('can:manage-leave-types')->group(function () {
        Route::resource('jenis-cuti', JenisCutiController::class)
            ->parameters(['jenis-cuti' => 'jenis_cuti'])
            ->names('leave-types')
            ->except('show');
    });

    // ---- Pengaturan (profil toko): identitas di struk + lebar kertas printer ----
    Route::middleware('can:manage-settings')->group(function () {
        Route::get('pengaturan', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('pengaturan', [SettingController::class, 'update'])->name('settings.update');
    });

    // ---- Pengguna (Tahap 1) ----
    Route::middleware('can:manage-users')->group(function () {
        Route::resource('pengguna', UserController::class)
            ->parameters(['pengguna' => 'user'])
            ->names('users')
            ->except('show');

        Route::patch('pengguna/{user}/status', [UserController::class, 'toggleStatus'])->name('users.toggle');
    });
});

require __DIR__.'/auth.php';
