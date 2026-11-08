<?php

/*
| Pengaturan: profil toko (identitas di struk + lebar kertas printer).
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-settings')->group(function () {
    Route::get('pengaturan', [SettingController::class, 'edit'])->name('settings.edit');
    Route::put('pengaturan', [SettingController::class, 'update'])->name('settings.update');
});
