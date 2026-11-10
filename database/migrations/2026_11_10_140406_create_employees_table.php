<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan. user_id (opsional, unik) menautkan karyawan ke akun login; Absensi, Jadwal, dan
 * Cuti yang sudah ada tetap memakai user_id, sehingga karyawan yang punya akun otomatis
 * terhubung ke datanya. Karyawan tanpa akun (misal staf dapur) tetap bisa dicatat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('employment_type', 15)->default('permanent'); // App\Enums\EmploymentType
            $table->string('identity_number', 30)->nullable();          // NIK KTP
            $table->date('birth_date')->nullable();
            $table->date('join_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('phone', 25)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
