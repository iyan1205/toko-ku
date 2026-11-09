<?php

/*
| Pengaturan: profil toko, cabang, pajak, metode pembayaran, struk & printer,
| penomoran dokumen, dan backup. Nilai disimpan di tabel settings (helper setting()),
| kecuali cabang (branches) dan metode pembayaran (payment_methods).
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\Settings\BackupController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\DocumentNumberingController;
use App\Http\Controllers\Settings\PaymentMethodController;
use App\Http\Controllers\Settings\ReceiptSettingController;
use App\Http\Controllers\Settings\StoreProfileController;
use App\Http\Controllers\Settings\TaxController;
use Illuminate\Support\Facades\Route;

Route::prefix('pengaturan')->name('settings.')->group(function () {
    // Profil toko. URL /pengaturan dan nama route settings.edit dipertahankan dari sebelum Fase 4.
    Route::get('/', [StoreProfileController::class, 'edit'])->middleware('permission:settings.store.view')->name('edit');
    Route::put('/', [StoreProfileController::class, 'update'])->middleware('permission:settings.store.update')->name('update');

    Route::resource('cabang', BranchController::class)
        ->parameters(['cabang' => 'branch'])
        ->names('branches')
        ->except('show')
        ->middlewareFor('index', 'permission:settings.branches.view')
        ->middlewareFor(['create', 'store'], 'permission:settings.branches.create')
        ->middlewareFor(['edit', 'update'], 'permission:settings.branches.update')
        ->middlewareFor('destroy', 'permission:settings.branches.delete');

    Route::get('pajak', [TaxController::class, 'edit'])->middleware('permission:settings.taxes.view')->name('taxes.edit');
    Route::put('pajak', [TaxController::class, 'update'])->middleware('permission:settings.taxes.update')->name('taxes.update');

    Route::get('metode-pembayaran', [PaymentMethodController::class, 'edit'])->middleware('permission:settings.payment-methods.view')->name('payment-methods.edit');
    Route::put('metode-pembayaran', [PaymentMethodController::class, 'update'])->middleware('permission:settings.payment-methods.update')->name('payment-methods.update');

    Route::get('struk', [ReceiptSettingController::class, 'edit'])->middleware('permission:settings.receipts.view')->name('receipts.edit');
    Route::put('struk', [ReceiptSettingController::class, 'update'])->middleware('permission:settings.receipts.update')->name('receipts.update');
    Route::get('struk/pratinjau', [ReceiptSettingController::class, 'preview'])->middleware('permission:settings.receipts.view')->name('receipts.preview');

    Route::get('penomoran', [DocumentNumberingController::class, 'edit'])->middleware('permission:settings.numbering.view')->name('numbering.edit');
    Route::put('penomoran', [DocumentNumberingController::class, 'update'])->middleware('permission:settings.numbering.update')->name('numbering.update');

    Route::get('backup', [BackupController::class, 'index'])->middleware('permission:settings.backup.view')->name('backups.index');
    Route::post('backup', [BackupController::class, 'store'])->middleware(['permission:settings.backup.create', 'throttle:5,1'])->name('backups.store');
    Route::get('backup/{name}', [BackupController::class, 'download'])->middleware('permission:settings.backup.view')->name('backups.download');
});
