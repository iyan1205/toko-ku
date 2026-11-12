<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen karyawan dengan masa berlaku (berkasnya lewat lampiran, morph employee_document) dan
 * offboarding: resign/PHK dengan checklist pengembalian barang/aset, lalu karyawan dan akun
 * loginnya dinonaktifkan pada hari terakhir kerja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                 // kunci config('sdm.document_types')
            $table->string('name', 150);
            $table->string('number', 60)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();     // kosong = berlaku seumur hidup
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'type']);
            $table->index('expires_at');
        });

        Schema::create('employee_offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('termination_type', 20);     // App\Enums\TerminationType
            $table->date('notice_date');
            $table->date('last_working_date');
            $table->string('reason', 255)->nullable();
            $table->boolean('deactivate_user')->default(true);
            $table->string('status', 15)->default('in_progress'); // in_progress | completed | cancelled
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable(); // karyawan & akun dinonaktifkan (hari terakhir kerja)
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'last_working_date']);
        });

        Schema::create('employee_offboarding_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_offboarding_id')->constrained()->cascadeOnDelete();
            $table->string('label', 150);
            $table->foreignId('fixed_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_offboarding_items');
        Schema::dropIfExists('employee_offboardings');
        Schema::dropIfExists('employee_documents');
    }
};
