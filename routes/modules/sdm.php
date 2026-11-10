<?php

/*
| SDM: karyawan, departemen & jabatan, absensi, jadwal & shift kerja, cuti.
| Di-include dari routes/web.php di dalam grup middleware auth.
| Setiap route dijaga izin "{modul}.{fitur}.{aksi}" (config/permission-matrix.php).
*/

use App\Http\Controllers\Sdm\AbsensiController;
use App\Http\Controllers\Sdm\CutiController;
use App\Http\Controllers\Sdm\DepartmentController;
use App\Http\Controllers\Sdm\EmployeeController;
use App\Http\Controllers\Sdm\JadwalController;
use App\Http\Controllers\Sdm\JenisCutiController;
use App\Http\Controllers\Sdm\PositionController;
use App\Http\Controllers\Sdm\ShiftController;
use Illuminate\Support\Facades\Route;

// ---- Absensi: view = semua pegawai, record = absen sendiri (AbsensiPolicy membatasi isi daftarnya). ----
Route::get('absensi', [AbsensiController::class, 'index'])
    ->middleware('permission:sdm.attendances.view|sdm.attendances.record')->name('attendances.index');

Route::middleware('permission:sdm.attendances.record')->group(function () {
    Route::post('absensi/masuk', [AbsensiController::class, 'clockIn'])->name('attendances.clock-in');
    Route::post('absensi/pulang', [AbsensiController::class, 'clockOut'])->name('attendances.clock-out');
});

// Catatan manual: izin/sakit/alpa dan koreksi jam (mis. lupa absen pulang).
Route::resource('absensi', AbsensiController::class)
    ->parameters(['absensi' => 'absensi'])
    ->names('attendances')
    ->only(['create', 'store', 'edit', 'update', 'destroy'])
    ->middlewareFor(['create', 'store'], 'permission:sdm.attendances.create')
    ->middlewareFor(['edit', 'update'], 'permission:sdm.attendances.update')
    ->middlewareFor('destroy', 'permission:sdm.attendances.delete');

// ---- Jadwal & shift kerja ----
Route::get('jadwal', [JadwalController::class, 'index'])->middleware('permission:sdm.schedules.view')->name('schedules.index');
Route::middleware('permission:sdm.schedules.update')->group(function () {
    Route::put('jadwal', [JadwalController::class, 'update'])->name('schedules.update');
    Route::post('jadwal/salin', [JadwalController::class, 'copy'])->name('schedules.copy');
});

Route::resource('shift', ShiftController::class)
    ->names('shifts')
    ->only(['index', 'store', 'update', 'destroy'])
    ->middlewareFor('index', 'permission:sdm.shifts.view')
    ->middlewareFor('store', 'permission:sdm.shifts.create')
    ->middlewareFor('update', 'permission:sdm.shifts.update')
    ->middlewareFor('destroy', 'permission:sdm.shifts.delete');

// ---- Cuti. Siapa yang boleh melihat/memutuskan tiap pengajuan dicek CutiPolicy (pemohon, sdm.leaves.view, penyetuju tahap). ----
// cuti/create didaftarkan lebih dulu agar tidak tertangkap sebagai cuti/{cuti}.
Route::middleware('permission:sdm.leaves.create')->group(function () {
    Route::get('cuti/create', [CutiController::class, 'create'])->name('leaves.create');
    Route::post('cuti', [CutiController::class, 'store'])->name('leaves.store');
});
Route::middleware('permission:sdm.leaves.view|sdm.leaves.create|sdm.leaves.approve')->group(function () {
    Route::get('cuti', [CutiController::class, 'index'])->name('leaves.index');
    Route::get('cuti/{cuti}', [CutiController::class, 'show'])->name('leaves.show');
    Route::post('cuti/{cuti}/setujui', [CutiController::class, 'approve'])->name('leaves.approve');
    Route::post('cuti/{cuti}/tolak', [CutiController::class, 'reject'])->name('leaves.reject');
    Route::post('cuti/{cuti}/batal', [CutiController::class, 'cancel'])->name('leaves.cancel');
});

// Jenis cuti, kuota, dan alur persetujuannya.
Route::resource('jenis-cuti', JenisCutiController::class)
    ->parameters(['jenis-cuti' => 'jenis_cuti'])
    ->names('leave-types')
    ->except('show')
    ->middlewareFor('index', 'permission:sdm.leave-types.view')
    ->middlewareFor(['create', 'store'], 'permission:sdm.leave-types.create')
    ->middlewareFor(['edit', 'update'], 'permission:sdm.leave-types.update')
    ->middlewareFor('destroy', 'permission:sdm.leave-types.delete');

// ---- Karyawan (relasi opsional ke akun login lewat employees.user_id) ----
Route::get('karyawan/ekspor', [EmployeeController::class, 'export'])->middleware('permission:sdm.employees.export')->name('employees.export');
Route::resource('karyawan', EmployeeController::class)
    ->parameters(['karyawan' => 'employee'])
    ->names('employees')
    ->except('show')
    ->middlewareFor('index', 'permission:sdm.employees.view')
    ->middlewareFor(['create', 'store'], 'permission:sdm.employees.create')
    ->middlewareFor(['edit', 'update'], 'permission:sdm.employees.update')
    ->middlewareFor('destroy', 'permission:sdm.employees.delete');

// ---- Departemen & jabatan (satu izin: sdm.departments.*) ----
foreach (['departemen' => [DepartmentController::class, 'departments', 'department'], 'jabatan' => [PositionController::class, 'positions', 'position']] as $uri => [$controller, $name, $param]) {
    Route::get("{$uri}/ekspor", [$controller, 'export'])->middleware('permission:sdm.departments.export')->name("{$name}.export");
    Route::resource($uri, $controller)
        ->parameters([$uri => $param])
        ->names($name)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'permission:sdm.departments.view')
        ->middlewareFor('store', 'permission:sdm.departments.create')
        ->middlewareFor('update', 'permission:sdm.departments.update')
        ->middlewareFor('destroy', 'permission:sdm.departments.delete');
}
