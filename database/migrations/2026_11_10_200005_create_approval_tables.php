<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesin persetujuan (approval workflow) yang bisa diatur di Pengaturan → Alur Persetujuan.
 *
 *   approval_flows    : alur per jenis dokumen + kondisi (misal total > Rp 5.000.000) + cabang
 *   approval_steps    : tahap berurutan; penyetuju = peran atau pengguna tertentu
 *   approval_requests : satu pengajuan dokumen; tahap disalin (snapshot) saat diajukan, jadi
 *                       mengubah alur tidak mengganggu pengajuan yang sedang berjalan
 *   approvals         : riwayat (diajukan, disetujui, ditolak, ditarik) beserta catatan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('document_type', 40)->index();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete(); // null = semua cabang
            $table->json('conditions')->nullable(); // [{field, operator, value}], semua harus terpenuhi
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('name', 100);
            $table->string('approver_type', 10); // role | user
            $table->foreignId('role_id')->nullable()->constrained(config('permission.table_names.roles', 'roles'))->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['approval_flow_id', 'sequence']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('approvable');
            $table->string('document_type', 40);
            $table->string('title', 255);
            $table->decimal('amount', 18, 2)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 15)->default('pending');
            $table->json('steps');
            $table->boolean('allow_self_approval')->default(false);
            $table->unsignedTinyInteger('current_step')->nullable();
            $table->unsignedBigInteger('current_role_id')->nullable();
            $table->unsignedBigInteger('current_user_id')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'current_role_id']);
            $table->index(['status', 'current_user_id']);
            $table->index(['submitted_by', 'status']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step')->nullable();
            $table->string('step_name', 100)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 15); // submitted | approved | rejected | cancelled
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_flows');
    }
};
