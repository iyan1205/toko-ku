<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR inti (Fase 12): data pribadi, pajak & BPJS, rekening, kontak darurat, foto, penempatan
 * (cabang, atasan langsung, grade), keluarga, pendidikan, pengalaman, riwayat kontrak, dan
 * riwayat mutasi/promosi. Semua kolom baru boleh kosong; karyawan lama ditempatkan di cabang utama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('name');            // App\Enums\Gender
            $table->string('birth_place', 100)->nullable()->after('identity_number');
            $table->string('religion', 20)->nullable()->after('birth_date');
            $table->string('marital_status', 15)->nullable()->after('religion'); // App\Enums\MaritalStatus
            $table->string('blood_type', 3)->nullable()->after('marital_status');
            $table->string('tax_id', 25)->nullable()->after('blood_type');       // NPWP
            $table->string('ptkp_status', 5)->nullable()->after('tax_id');       // App\Enums\PtkpStatus
            $table->string('bpjs_health_number', 30)->nullable()->after('ptkp_status');
            $table->string('bpjs_employment_number', 30)->nullable()->after('bpjs_health_number');
            $table->string('bank_name', 100)->nullable()->after('bpjs_employment_number');
            $table->string('bank_account_number', 40)->nullable()->after('bank_name');
            $table->string('bank_account_name', 150)->nullable()->after('bank_account_number');
            $table->string('emergency_contact_name', 150)->nullable()->after('address');
            $table->string('emergency_contact_relation', 50)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_phone', 25)->nullable()->after('emergency_contact_relation');
            $table->string('photo_path', 255)->nullable()->after('emergency_contact_phone');
            $table->foreignId('branch_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('supervisor_id')->nullable()->after('position_id')->constrained('employees')->nullOnDelete();
            $table->string('grade', 20)->nullable()->after('supervisor_id');
            $table->string('termination_type', 20)->nullable()->after('end_date'); // App\Enums\TerminationType
            $table->string('termination_reason', 255)->nullable()->after('termination_type');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        $main = DB::table('branches')->where('is_default', true)->value('id');
        if ($main !== null) {
            DB::table('employees')->whereNull('branch_id')->update(['branch_id' => $main]);
        }

        Schema::create('employee_family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('relation', 15);                 // App\Enums\FamilyRelation
            $table->string('identity_number', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('occupation', 100)->nullable();
            $table->boolean('is_dependent')->default(false); // tanggungan (untuk PTKP)
            $table->string('phone', 25)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('level', 5);                     // App\Enums\EducationLevel
            $table->string('institution', 150);
            $table->string('major', 100)->nullable();
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('company', 150);
            $table->string('position', 100)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('number', 50)->nullable();       // nomor perjanjian kerja
            $table->string('employment_type', 15);          // App\Enums\EmploymentType
            $table->date('start_date');
            $table->date('end_date')->nullable();           // kosong = tanpa batas (PKWTT)
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'start_date']);
            $table->index('end_date');
        });

        Schema::create('employee_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 15);                     // App\Enums\EmployeeMovementType
            $table->date('effective_date');
            $table->string('decree_number', 50)->nullable(); // nomor SK
            foreach (['from', 'to'] as $side) {
                $table->foreignId("{$side}_branch_id")->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId("{$side}_department_id")->nullable()->constrained('departments')->nullOnDelete();
                $table->foreignId("{$side}_position_id")->nullable()->constrained('positions')->nullOnDelete();
                $table->foreignId("{$side}_supervisor_id")->nullable()->constrained('employees')->nullOnDelete();
                $table->string("{$side}_grade", 20)->nullable();
            }
            $table->string('notes', 255)->nullable();
            $table->timestamp('applied_at')->nullable();    // kosong = menunggu tanggal efektif
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_movements');
        Schema::dropIfExists('employee_contracts');
        Schema::dropIfExists('employee_experiences');
        Schema::dropIfExists('employee_educations');
        Schema::dropIfExists('employee_family_members');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn([
                'gender', 'birth_place', 'religion', 'marital_status', 'blood_type', 'tax_id', 'ptkp_status',
                'bpjs_health_number', 'bpjs_employment_number', 'bank_name', 'bank_account_number', 'bank_account_name',
                'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone', 'photo_path',
                'grade', 'termination_type', 'termination_reason',
            ]);
        });
    }
};
