<?php

/*
| Pengguna & Hak Akses.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-users')->group(function () {
    Route::resource('pengguna', UserController::class)
        ->parameters(['pengguna' => 'user'])
        ->names('users')
        ->except('show');

    Route::patch('pengguna/{user}/status', [UserController::class, 'toggleStatus'])->name('users.toggle');
});
