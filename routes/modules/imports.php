<?php

/*
| Impor massal (kerangka Fase 8): satu set route per jenis di config/imports.php, dijaga izin
| jenis itu (misal pos.customers.import). URL /impor/{slug}, nama route imports.{jenis}.*.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

foreach (config('imports.types', []) as $type => $config) {
    Route::prefix('impor/'.($config['slug'] ?? $type))->name("imports.{$type}.")->middleware("permission:{$config['permission']}")->group(function () {
        Route::get('/', [ImportController::class, 'create'])->name('create');
        Route::get('templat', [ImportController::class, 'template'])->name('template');
        Route::post('/', [ImportController::class, 'preview'])->middleware('throttle:20,1')->name('preview');
        Route::get('{batch}', [ImportController::class, 'show'])->whereNumber('batch')->name('show');
        Route::post('{batch}/proses', [ImportController::class, 'commit'])->whereNumber('batch')->name('commit');
        Route::get('{batch}/kesalahan', [ImportController::class, 'errors'])->whereNumber('batch')->name('errors');
    });
}
