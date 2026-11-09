<?php

/*
| Pengaturan: profil toko (identitas di struk + lebar kertas printer).
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::get('pengaturan', [SettingController::class, 'edit'])->middleware('permission:settings.store.view')->name('settings.edit');
Route::put('pengaturan', [SettingController::class, 'update'])->middleware('permission:settings.store.update')->name('settings.update');
