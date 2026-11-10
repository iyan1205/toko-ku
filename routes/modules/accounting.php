<?php

/*
| Akuntansi: bagan akun (COA), jurnal umum (Fase 7), dan kunci periode (Fase 8).
| Buku besar, kas & bank, laporan keuangan, dan tutup buku menyusul (lihat docs/ROADMAP.md).
| Di-include dari routes/web.php di dalam grup middleware auth. Setiap route dijaga izin
| "{modul}.{fitur}.{aksi}" (config/permission-matrix.php). Route statis ditulis di atas
| route ber-parameter.
*/

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\JournalController;
use Illuminate\Support\Facades\Route;

Route::prefix('akuntansi')->group(function () {
    // ---- Bagan akun ----
    Route::get('akun/ekspor', [AccountController::class, 'export'])->middleware('permission:accounting.coa.export')->name('accounts.export');
    Route::post('akun/pasang-standar', [AccountController::class, 'installStandard'])->middleware('permission:accounting.coa.create')->name('accounts.install');
    Route::resource('akun', AccountController::class)
        ->parameters(['akun' => 'account'])
        ->names('accounts')
        ->except('show')
        ->middlewareFor('index', 'permission:accounting.coa.view')
        ->middlewareFor(['create', 'store'], 'permission:accounting.coa.create')
        ->middlewareFor(['edit', 'update'], 'permission:accounting.coa.update')
        ->middlewareFor('destroy', 'permission:accounting.coa.delete');

    // ---- Jurnal umum: draf bisa diubah/diajukan/dibatalkan; jurnal posted dikoreksi lewat jurnal pembalik. ----
    Route::get('jurnal/ekspor', [JournalController::class, 'export'])->middleware('permission:accounting.journals.export')->name('journals.export');
    Route::middleware('permission:accounting.journals.create')->group(function () {
        Route::get('jurnal/buat', [JournalController::class, 'create'])->name('journals.create');
        Route::post('jurnal', [JournalController::class, 'store'])->name('journals.store');
        Route::prefix('jurnal/{journal}')->whereNumber('journal')->group(function () {
            Route::get('ubah', [JournalController::class, 'edit'])->name('journals.edit');
            Route::put('/', [JournalController::class, 'update'])->name('journals.update');
            Route::post('posting', [JournalController::class, 'post'])->name('journals.post');
            Route::post('batal', [JournalController::class, 'cancel'])->name('journals.cancel');
        });
    });
    Route::middleware('permission:accounting.journals.view')->group(function () {
        Route::get('jurnal', [JournalController::class, 'index'])->name('journals.index');
        Route::get('jurnal/{journal}', [JournalController::class, 'show'])->whereNumber('journal')->name('journals.show');
    });
    Route::post('jurnal/{journal}/balik', [JournalController::class, 'reverse'])
        ->whereNumber('journal')->middleware('permission:accounting.journals.void')->name('journals.reverse');

    // ---- Kunci periode: tutup / buka kembali per bulan. ----
    Route::get('kunci-periode', [FiscalPeriodController::class, 'index'])->middleware('permission:accounting.periods.view')->name('fiscal-periods.index');
    Route::prefix('kunci-periode/{tahun}/{bulan}')->whereNumber(['tahun', 'bulan'])->middleware('permission:accounting.periods.update')->group(function () {
        Route::post('tutup', [FiscalPeriodController::class, 'close'])->name('fiscal-periods.close');
        Route::post('buka', [FiscalPeriodController::class, 'reopen'])->name('fiscal-periods.reopen');
    });
});
