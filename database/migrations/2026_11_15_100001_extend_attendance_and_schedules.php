<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 13: absensi dan jadwal per karyawan.
 *
 *   absensis/jadwals.employee_id : diisi dari employees.user_id untuk data lama. user_id menjadi
 *                                  opsional agar karyawan tanpa akun login (misal dari mesin
 *                                  fingerprint) tetap bisa dicatat.
 *   absensis                      : menit terlambat & pulang cepat, sumber catatan, foto selfie,
 *                                  lokasi pulang, jarak dari cabang saat absen masuk.
 *   shifts                        : toleransi terlambat/pulang cepat, istirahat, warna kalender.
 *   branches                      : titik koordinat dan radius absen.
 *   employees.attendance_pin      : nomor PIN/ID di mesin fingerprint.
 *   holidays                      : hari libur nasional/cabang.
 *   shift_rotations               : pola rotasi shift (urutan shift/libur yang berulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_tolerance_minutes')->nullable()->after('jam_pulang');
            $table->unsignedSmallInteger('early_leave_tolerance_minutes')->nullable()->after('late_tolerance_minutes');
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('early_leave_tolerance_minutes');
            $table->string('color', 20)->nullable()->after('break_minutes');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('phone');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('attendance_radius_m')->nullable()->after('longitude');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('attendance_pin', 30)->nullable()->unique()->after('code');
        });

        Schema::table('absensis', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('late_minutes')->default(0)->after('clock_out');
            $table->unsignedInteger('early_leave_minutes')->default(0)->after('late_minutes');
            $table->string('source', 15)->default('web')->after('early_leave_minutes');
            $table->string('clock_in_photo', 255)->nullable()->after('location');
            $table->string('clock_out_photo', 255)->nullable()->after('clock_in_photo');
            $table->string('clock_out_location', 50)->nullable()->after('clock_out_photo');
            $table->unsignedInteger('distance_m')->nullable()->after('clock_out_location');
        });
        Schema::table('absensis', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->unique(['employee_id', 'tanggal']);
        });

        Schema::table('jadwals', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
        Schema::table('jadwals', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->unique(['employee_id', 'tanggal']);
        });

        foreach (['absensis', 'jadwals'] as $name) {
            DB::table($name)->whereNull('employee_id')->update([
                'employee_id' => DB::raw("(select employees.id from employees where employees.user_id = {$name}.user_id and employees.deleted_at is null limit 1)"),
            ]);
        }

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('name', 100);
            $table->boolean('is_national')->default(true);
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shift_rotations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->json('pattern');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_rotations');
        Schema::dropIfExists('holidays');
        Schema::table('jadwals', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'tanggal']);
            $table->dropConstrainedForeignId('employee_id');
        });
        Schema::table('absensis', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'tanggal']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['late_minutes', 'early_leave_minutes', 'source', 'clock_in_photo', 'clock_out_photo', 'clock_out_location', 'distance_m']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['attendance_pin']);
            $table->dropColumn('attendance_pin');
        });
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'attendance_radius_m']));
        Schema::table('shifts', fn (Blueprint $table) => $table->dropColumn(['late_tolerance_minutes', 'early_leave_tolerance_minutes', 'break_minutes', 'color']));
    }
};
