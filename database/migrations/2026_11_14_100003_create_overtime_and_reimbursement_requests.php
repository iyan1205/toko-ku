<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan lembur dan reimbursement dari Layanan Mandiri (ESS). Status baku dokumen
 * (submitted → approved, atau cancelled; is_rejected = ditolak), persetujuan lewat ApprovalService
 * bila ada alurnya. Penggajian (Fase 14) membaca yang disetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['overtime_requests', 'reimbursement_requests'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->string('number', 30)->unique();
                $table->foreignId('employee_id')->constrained()->restrictOnDelete();
                if ($name === 'overtime_requests') {
                    $table->date('overtime_date');
                    $table->dateTime('starts_at');
                    $table->dateTime('ends_at');
                    $table->unsignedInteger('duration_minutes');
                } else {
                    $table->date('expense_date');
                    $table->string('category', 30);   // kunci config('sdm.reimbursement_categories')
                    $table->decimal('amount', 18, 2);
                }
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
                $table->index(['employee_id', 'status']);
                $table->index(['branch_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_requests');
        Schema::dropIfExists('overtime_requests');
    }
};
