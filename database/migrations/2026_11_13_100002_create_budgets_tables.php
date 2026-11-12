<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anggaran per tahun: nilai per akun per bulan, untuk semua cabang atau satu cabang, dan opsional
 * satu cost center. Realisasinya dibaca dari jurnal posted (LedgerService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('name', 100);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['budget_id', 'account_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
