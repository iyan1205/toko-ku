<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 13b: koreksi absensi (pengajuan karyawan + persetujuan), aturan upah lembur bertanggal
 * berlaku (Pengaturan → SDM), dan gaji pokok karyawan untuk menghitung upah lembur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->foreignId('absensi_id')->nullable()->constrained('absensis')->nullOnDelete();
            // Isi absensi sebelum koreksi (untuk jejak) dan jam yang diminta.
            $table->string('original_status', 20)->nullable();
            $table->dateTime('original_clock_in')->nullable();
            $table->dateTime('original_clock_out')->nullable();
            $table->dateTime('requested_clock_in')->nullable();
            $table->dateTime('requested_clock_out')->nullable();
            $table->string('reason', 500);
            $table->string('status', 15)->default('draft'); // App\Enums\DocumentStatus
            $table->boolean('is_rejected')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'attendance_date']);
            $table->index(['branch_id', 'status']);
        });

        // Aturan upah lembur: baris dengan effective_from terakhir <= tanggal lembur yang dipakai.
        Schema::create('overtime_rules', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->string('mode', 15)->default('multiplier'); // multiplier | flat
            $table->unsignedSmallInteger('divisor')->default(173); // upah sejam = gaji pokok / pembagi
            $table->json('workday_tiers');      // [{hours: 1, multiplier: "1.5"}, {hours: null, multiplier: "2"}]
            $table->json('rest_day_tiers_6');   // hari istirahat/libur, 6 hari kerja seminggu
            $table->json('rest_day_tiers_5');   // hari istirahat/libur, 5 hari kerja seminggu
            $table->decimal('flat_rate', 18, 2)->nullable(); // mode flat: Rupiah per jam
            $table->unsignedSmallInteger('rounding_minutes')->default(0); // dibulatkan ke bawah per n menit
            $table->unsignedSmallInteger('min_minutes')->default(0);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Titik awal: PP 35/2021 (berlaku 2 Feb 2021). Pemilik menambah baris baru bila aturannya berubah.
        DB::table('overtime_rules')->insert([
            'effective_from' => '2021-02-02',
            'mode' => 'multiplier',
            'divisor' => 173,
            'workday_tiers' => json_encode([['hours' => 1, 'multiplier' => '1.5'], ['hours' => null, 'multiplier' => '2']]),
            'rest_day_tiers_6' => json_encode([['hours' => 7, 'multiplier' => '2'], ['hours' => 1, 'multiplier' => '3'], ['hours' => null, 'multiplier' => '4']]),
            'rest_day_tiers_5' => json_encode([['hours' => 8, 'multiplier' => '2'], ['hours' => 1, 'multiplier' => '3'], ['hours' => null, 'multiplier' => '4']]),
            'rounding_minutes' => 0,
            'min_minutes' => 0,
            'notes' => 'PP 35/2021: upah sejam = 1/173 upah sebulan.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('base_salary', 18, 2)->nullable()->after('grade');
            $table->boolean('overtime_eligible')->default(true)->after('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'overtime_eligible']);
        });
        Schema::dropIfExists('overtime_rules');
        Schema::dropIfExists('attendance_corrections');
    }
};
