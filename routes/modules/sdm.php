<?php

/*
| SDM: absensi, jadwal & shift kerja, cuti.
| Di-include dari routes/web.php di dalam grup middleware auth.
*/

use App\Http\Controllers\Sdm\AbsensiController;
use App\Http\Controllers\Sdm\CutiController;
use App\Http\Controllers\Sdm\JadwalController;
use App\Http\Controllers\Sdm\JenisCutiController;
use App\Http\Controllers\Sdm\ShiftController;
use Illuminate\Support\Facades\Route;

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
