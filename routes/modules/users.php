<?php

/*
| Pengguna & Hak Akses: akun pengguna, peran, matriks hak akses, dan log aktivitas.
| Di-include dari routes/web.php di dalam grup middleware auth.
| Aturan tambahan (tidak boleh menyentuh Super Admin bila bukan Super Admin, dst.) ada di
| UserPolicy, RolePolicy, dan service masing-masing.
*/

use App\Http\Controllers\Access\ActivityLogController;
use App\Http\Controllers\Access\PermissionMatrixController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserController;
use Illuminate\Support\Facades\Route;

Route::resource('pengguna', UserController::class)
    ->parameters(['pengguna' => 'user'])
    ->names('users')
    ->except('show')
    ->middlewareFor('index', 'permission:access.users.view')
    ->middlewareFor(['create', 'store'], 'permission:access.users.create')
    ->middlewareFor(['edit', 'update'], 'permission:access.users.update')
    ->middlewareFor('destroy', 'permission:access.users.delete');

Route::middleware('permission:access.users.update')->group(function () {
    Route::patch('pengguna/{user}/status', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::put('pengguna/{user}/kata-sandi', [UserController::class, 'resetPassword'])->name('users.password');
});

Route::resource('peran', RoleController::class)
    ->parameters(['peran' => 'role'])
    ->names('roles')
    ->except('show')
    ->middlewareFor('index', 'permission:access.roles.view')
    ->middlewareFor(['create', 'store'], 'permission:access.roles.create')
    ->middlewareFor(['edit', 'update'], 'permission:access.roles.update')
    ->middlewareFor('destroy', 'permission:access.roles.delete');

Route::get('hak-akses', [PermissionMatrixController::class, 'index'])->middleware('permission:access.permissions.view')->name('permissions.index');
Route::put('hak-akses/{role}', [PermissionMatrixController::class, 'update'])->middleware('permission:access.permissions.update')->name('permissions.update');

Route::get('log-aktivitas', ActivityLogController::class)->middleware('permission:access.activity-log.view')->name('activity-log.index');
