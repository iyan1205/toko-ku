<?php

/*
| SDM: karyawan, departemen & jabatan, absensi, jadwal & shift kerja, cuti.
| Di-include dari routes/web.php di dalam grup middleware auth.
| Setiap route dijaga izin "{modul}.{fitur}.{aksi}" (config/permission-matrix.php).
*/

use App\Http\Controllers\Sdm\AbsensiController;
use App\Http\Controllers\Sdm\AttendanceRecapController;
use App\Http\Controllers\Sdm\CutiController;
use App\Http\Controllers\Sdm\DepartmentController;
use App\Http\Controllers\Sdm\EmployeeController;
use App\Http\Controllers\Sdm\EmployeeDocumentController;
use App\Http\Controllers\Sdm\EmployeeHistoryController;
use App\Http\Controllers\Sdm\EmployeeRequestController;
use App\Http\Controllers\Sdm\HolidayController;
use App\Http\Controllers\Sdm\JadwalController;
use App\Http\Controllers\Sdm\JenisCutiController;
use App\Http\Controllers\Sdm\OffboardingController;
use App\Http\Controllers\Sdm\OrgChartController;
use App\Http\Controllers\Sdm\PositionController;
use App\Http\Controllers\Sdm\SelfServiceController;
use App\Http\Controllers\Sdm\SelfServiceRequestController;
use App\Http\Controllers\Sdm\ShiftController;
use App\Http\Controllers\Sdm\ShiftRotationController;
use Illuminate\Support\Facades\Route;

// ---- Absensi: view = semua pegawai, record = absen sendiri (AbsensiPolicy membatasi isi daftarnya). ----
Route::get('absensi', [AbsensiController::class, 'index'])
    ->middleware('permission:sdm.attendances.view|sdm.attendances.record')->name('attendances.index');

Route::middleware('permission:sdm.attendances.record')->group(function () {
    Route::post('absensi/masuk', [AbsensiController::class, 'clockIn'])->name('attendances.clock-in');
    Route::post('absensi/pulang', [AbsensiController::class, 'clockOut'])->name('attendances.clock-out');
});

// Foto selfie absen: pemilik atau yang boleh melihat absensi semua pegawai (diperiksa di controller).
Route::get('absensi/{absensi}/foto/{jenis}', [AbsensiController::class, 'photo'])
    ->whereIn('jenis', ['masuk', 'pulang'])
    ->middleware('permission:sdm.attendances.view|sdm.attendances.record')->name('attendances.photo');

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
Route::get('jadwal/bulanan', [JadwalController::class, 'month'])->middleware('permission:sdm.schedules.view')->name('schedules.month');
Route::middleware('permission:sdm.schedules.update')->group(function () {
    Route::put('jadwal', [JadwalController::class, 'update'])->name('schedules.update');
    Route::post('jadwal/salin', [JadwalController::class, 'copy'])->name('schedules.copy');
});

Route::resource('hari-libur', HolidayController::class)
    ->parameters(['hari-libur' => 'holiday'])
    ->names('holidays')
    ->only(['index', 'store', 'update', 'destroy'])
    ->middlewareFor('index', 'permission:sdm.holidays.view')
    ->middlewareFor('store', 'permission:sdm.holidays.create')
    ->middlewareFor('update', 'permission:sdm.holidays.update')
    ->middlewareFor('destroy', 'permission:sdm.holidays.delete');

// Pola rotasi shift; menerapkannya ke jadwal = sdm.schedules.update. Didaftarkan sebelum resource shift.
Route::get('shift/rotasi', [ShiftRotationController::class, 'index'])->middleware('permission:sdm.shifts.view')->name('shift-rotations.index');
Route::post('shift/rotasi', [ShiftRotationController::class, 'store'])->middleware('permission:sdm.shifts.create')->name('shift-rotations.store');
Route::put('shift/rotasi/{rotation}', [ShiftRotationController::class, 'update'])->middleware('permission:sdm.shifts.update')->name('shift-rotations.update');
Route::delete('shift/rotasi/{rotation}', [ShiftRotationController::class, 'destroy'])->middleware('permission:sdm.shifts.delete')->name('shift-rotations.destroy');
Route::post('shift/rotasi/{rotation}/terapkan', [ShiftRotationController::class, 'apply'])->middleware('permission:sdm.schedules.update')->name('shift-rotations.apply');

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
// Foto: pemegang sdm.employees.view atau karyawan itu sendiri (dicek di controller).
Route::get('karyawan/{employee}/foto', [EmployeeController::class, 'photo'])->middleware('permission:sdm.employees.view|sdm.self-service.view')->name('employees.photo');
Route::middleware('permission:sdm.employees.update')->prefix('karyawan/{employee}')->group(function () {
    Route::post('kontrak', [EmployeeHistoryController::class, 'storeContract'])->name('employees.contracts.store');
    Route::delete('kontrak/{contract}', [EmployeeHistoryController::class, 'destroyContract'])->name('employees.contracts.destroy');
    Route::post('mutasi', [EmployeeHistoryController::class, 'storeMovement'])->name('employees.movements.store');
    Route::delete('mutasi/{movement}', [EmployeeHistoryController::class, 'destroyMovement'])->name('employees.movements.destroy');
    Route::post('dokumen', [EmployeeDocumentController::class, 'store'])->name('employees.documents.store');
    Route::delete('dokumen/{document}', [EmployeeDocumentController::class, 'destroy'])->name('employees.documents.destroy');
});

// ---- Layanan Mandiri (ESS): data milik karyawan yang login sendiri ----
Route::prefix('saya')->name('ess.')->group(function () {
    Route::middleware('permission:sdm.self-service.view')->group(function () {
        Route::get('/', [SelfServiceController::class, 'index'])->name('index');
        Route::get('profil', [SelfServiceController::class, 'profile'])->name('profile');
        Route::get('absensi', [SelfServiceController::class, 'attendance'])->name('attendance');
        Route::get('cuti', [SelfServiceController::class, 'leaves'])->name('leaves');
        Route::get('slip-gaji', [SelfServiceController::class, 'payslips'])->name('payslips');
    });
    Route::middleware('permission:sdm.overtime.create')->group(function () {
        Route::get('lembur', [SelfServiceRequestController::class, 'overtime'])->name('overtime');
        Route::post('lembur', [SelfServiceRequestController::class, 'storeOvertime'])->name('overtime.store');
        Route::post('lembur/{id}/batal', [SelfServiceRequestController::class, 'cancelOvertime'])->whereNumber('id')->name('overtime.cancel');
    });
    Route::middleware('permission:sdm.attendance-corrections.create')->group(function () {
        Route::post('absensi/koreksi', [SelfServiceRequestController::class, 'storeCorrection'])->name('corrections.store');
        Route::post('absensi/koreksi/{id}/batal', [SelfServiceRequestController::class, 'cancelCorrection'])->whereNumber('id')->name('corrections.cancel');
    });
    Route::middleware('permission:sdm.reimbursements.create')->group(function () {
        Route::get('reimbursement', [SelfServiceRequestController::class, 'reimbursements'])->name('reimbursements');
        Route::post('reimbursement', [SelfServiceRequestController::class, 'storeReimbursement'])->name('reimbursements.store');
        Route::post('reimbursement/{id}/batal', [SelfServiceRequestController::class, 'cancelReimbursement'])->whereNumber('id')->name('reimbursements.cancel');
    });
});

// ---- Rekap absensi per periode (siap dipakai penggajian) ----
Route::get('rekap-absensi', [AttendanceRecapController::class, 'index'])->middleware('permission:sdm.attendance-reports.view')->name('attendance-recap.index');
Route::get('rekap-absensi/ekspor', [AttendanceRecapController::class, 'export'])->middleware('permission:sdm.attendance-reports.export')->name('attendance-recap.export');
Route::get('rekap-absensi/{employee}', [AttendanceRecapController::class, 'show'])->whereNumber('employee')->middleware('permission:sdm.attendance-reports.view')->name('attendance-recap.show');

// ---- Pengajuan lembur, reimbursement & koreksi absensi (SDM): daftar, ekspor, keputusan langsung tanpa alur ----
foreach (['lembur' => ['overtime', 'sdm.overtime'], 'reimbursement' => ['reimbursements', 'sdm.reimbursements'], 'koreksi-absensi' => ['attendance-corrections', 'sdm.attendance-corrections']] as $jenis => [$name, $izin]) {
    Route::prefix("pengajuan-{$jenis}")->group(function () use ($name, $izin) {
        Route::get('/', [EmployeeRequestController::class, 'index'])->middleware("permission:{$izin}.view")->name("{$name}.index");
        Route::get('ekspor', [EmployeeRequestController::class, 'export'])->middleware("permission:{$izin}.export")->name("{$name}.export");
        Route::post('{id}/setujui', [EmployeeRequestController::class, 'approve'])->whereNumber('id')->middleware("permission:{$izin}.approve")->name("{$name}.approve");
        Route::post('{id}/tolak', [EmployeeRequestController::class, 'reject'])->whereNumber('id')->middleware("permission:{$izin}.approve")->name("{$name}.reject");
    });
}

// ---- Struktur organisasi dan offboarding (Fase 12) ----
Route::get('struktur-organisasi', [OrgChartController::class, 'index'])->middleware('permission:sdm.organization.view')->name('org-chart.index');
Route::get('offboarding', [OffboardingController::class, 'index'])->middleware('permission:sdm.offboarding.view')->name('offboardings.index');
Route::get('offboarding/{offboarding}', [OffboardingController::class, 'show'])->whereNumber('offboarding')->middleware('permission:sdm.offboarding.view')->name('offboardings.show');
Route::post('karyawan/{employee}/offboarding', [OffboardingController::class, 'store'])->middleware('permission:sdm.offboarding.create')->name('offboardings.store');
Route::middleware('permission:sdm.offboarding.create')->prefix('offboarding/{offboarding}')->whereNumber('offboarding')->group(function () {
    Route::post('checklist', [OffboardingController::class, 'addItem'])->name('offboardings.items.store');
    Route::post('checklist/{item}', [OffboardingController::class, 'toggleItem'])->whereNumber('item')->name('offboardings.items.toggle');
});
Route::middleware('permission:sdm.offboarding.approve')->prefix('offboarding/{offboarding}')->whereNumber('offboarding')->group(function () {
    Route::post('selesai', [OffboardingController::class, 'complete'])->name('offboardings.complete');
    Route::post('batal', [OffboardingController::class, 'cancel'])->name('offboardings.cancel');
});
Route::resource('karyawan', EmployeeController::class)
    ->parameters(['karyawan' => 'employee'])
    ->names('employees')
    ->middlewareFor(['index', 'show'], 'permission:sdm.employees.view')
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
