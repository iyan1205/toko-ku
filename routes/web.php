<?php

use App\Http\Controllers\Approval\ApprovalController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\CurrentBranchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\UiKitController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Route umum ada di sini; route per modul ada di routes/modules/*.php.
| Semua route membutuhkan login (grup auth di bawah). Route modul juga dijaga
| middleware permission:{modul}.{fitur}.{aksi} (lihat config/permission-matrix.php).
*/

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Pencarian global di topbar (JSON). Hasil disaring sesuai hak akses.
    Route::get('pencarian', SearchController::class)->middleware('throttle:60,1')->name('search');

    // Bagian "stok menipis" di dropdown lonceng (JSON), diambil saat lonceng dibuka.
    Route::get('notifikasi', NotificationController::class)->middleware('permission:inventory.stock.view')->name('notifications');

    // Pusat notifikasi milik sendiri: lonceng (JSON), halaman semua notifikasi, tandai dibaca.
    Route::prefix('notifikasi')->name('notifications.')->group(function () {
        Route::get('semua', [NotificationCenterController::class, 'index'])->name('index');
        Route::get('terbaru', [NotificationCenterController::class, 'feed'])->middleware('throttle:120,1')->name('feed');
        Route::post('baca-semua', [NotificationCenterController::class, 'readAll'])->name('read-all');
        Route::get('{notification}/buka', [NotificationCenterController::class, 'open'])->whereUuid('notification')->name('open');
        Route::post('{notification}/baca', [NotificationCenterController::class, 'read'])->whereUuid('notification')->name('read');
    });

    // Profil saya: semua pengguna login boleh mengubah nama, nomor HP, dan kata sandinya sendiri.
    Route::get('profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profil/kata-sandi', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::put('profil/notifikasi', [NotificationCenterController::class, 'updatePreferences'])->name('profile.notifications');

    // Persetujuan Saya: semua pengguna login. Siapa yang boleh menyetujui satu tahap diperiksa ApprovalService.
    Route::get('persetujuan', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::prefix('persetujuan/{approvalRequest}')->whereNumber('approvalRequest')->name('approvals.')->group(function () {
        Route::post('setujui', [ApprovalController::class, 'approve'])->name('approve');
        Route::post('tolak', [ApprovalController::class, 'reject'])->name('reject');
        Route::post('tarik', [ApprovalController::class, 'cancel'])->name('cancel');
    });

    // Pilihan cabang aktif di topbar (disimpan di session). Semua pengguna login boleh memilih.
    Route::put('cabang-aktif', CurrentBranchController::class)->name('current-branch.update');

    // Halaman "Segera Hadir" untuk menu berstatus coming_soon di config/menu.php.
    Route::get('segera-hadir/{key}', ComingSoonController::class)->name('coming-soon');

    // Katalog komponen UI untuk pengembang. Hanya environment local + Super Admin (dicek di controller).
    Route::get('dev/ui-kit', UiKitController::class)->name('dev.ui-kit');

    require __DIR__.'/modules/pos.php';
    require __DIR__.'/modules/inventory.php';
    require __DIR__.'/modules/purchasing.php';
    require __DIR__.'/modules/accounting.php';
    require __DIR__.'/modules/reports.php';
    require __DIR__.'/modules/sdm.php';
    require __DIR__.'/modules/users.php';
    require __DIR__.'/modules/settings.php';
});

require __DIR__.'/auth.php';
