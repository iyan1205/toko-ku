<?php

use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\UiKitController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Route umum ada di sini; route per modul ada di routes/modules/*.php.
| Semua route modul membutuhkan login (grup auth di bawah).
*/

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Pencarian global di topbar (JSON). Hasil disaring sesuai hak akses.
    Route::get('pencarian', SearchController::class)->middleware('throttle:60,1')->name('search');

    // Isi dropdown notifikasi di topbar (JSON), diambil saat lonceng dibuka.
    Route::get('notifikasi', NotificationController::class)->name('notifications');

    // Halaman "Segera Hadir" untuk menu berstatus coming_soon di config/menu.php.
    Route::get('segera-hadir/{key}', ComingSoonController::class)->name('coming-soon');

    // Katalog komponen UI untuk pengembang. Hanya environment local + Super Admin (dicek di controller).
    Route::get('dev/ui-kit', UiKitController::class)->name('dev.ui-kit');

    require __DIR__.'/modules/pos.php';
    require __DIR__.'/modules/inventory.php';
    require __DIR__.'/modules/purchasing.php';
    require __DIR__.'/modules/reports.php';
    require __DIR__.'/modules/sdm.php';
    require __DIR__.'/modules/users.php';
    require __DIR__.'/modules/settings.php';
});

require __DIR__.'/auth.php';
