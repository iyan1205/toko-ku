<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 13c: aturan jenis cuti (kategori, dibayar/tidak, surat keterangan, batas hari, masa kerja
 * minimal, jenis kelamin, hitung hari kerja, saldo tahunan per masa kerja) dan cuti per karyawan
 * (employee_id, branch_id untuk mesin persetujuan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_cutis', function (Blueprint $table) {
            $table->string('category', 20)->default('other')->after('nama'); // annual|sick|maternity|special|unpaid|other
            $table->boolean('is_paid')->default(true)->after('status_absensi');
            $table->boolean('requires_document')->default(false)->after('is_paid');
            $table->unsignedSmallInteger('document_after_days')->default(0)->after('requires_document'); // surat wajib bila lebih dari n hari
            $table->unsignedSmallInteger('max_days_per_request')->nullable()->after('document_after_days');
            $table->unsignedSmallInteger('min_service_months')->default(0)->after('max_days_per_request');
            $table->string('gender', 10)->nullable()->after('min_service_months'); // App\Enums\Gender; null = semua
            $table->boolean('working_days_only')->default(true)->after('gender'); // hitung hari kerja saja
            $table->json('quota_tiers')->nullable()->after('kuota_tahunan'); // [{months: 12, days: 12}, ...]
        });

        // Jenis lama: kategori ditebak dari status absensinya; hari dihitung kalender seperti sebelumnya.
        DB::table('jenis_cutis')->update(['working_days_only' => false]);
        DB::table('jenis_cutis')->where('status_absensi', 'sakit')->update(['category' => 'sick']);
        DB::table('jenis_cutis')->where('status_absensi', 'cuti')->whereNotNull('kuota_tahunan')->update(['category' => 'annual']);

        Schema::table('cutis', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
        });

        DB::table('cutis')->update([
            'employee_id' => DB::raw('(select employees.id from employees where employees.user_id = cutis.user_id and employees.deleted_at is null limit 1)'),
        ]);
        DB::table('cutis')->whereNotNull('employee_id')->update([
            'branch_id' => DB::raw('(select employees.branch_id from employees where employees.id = cutis.employee_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('cutis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('employee_id');
        });
        Schema::table('jenis_cutis', function (Blueprint $table) {
            $table->dropColumn(['category', 'is_paid', 'requires_document', 'document_after_days', 'max_days_per_request', 'min_service_months', 'gender', 'working_days_only', 'quota_tiers']);
        });
    }
};
