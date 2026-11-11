<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekonsiliasi bank: mutasi rekening koran yang diimpor (CSV/Excel) per rekening dan periode.
 * Setiap baris mutasi dicocokkan dengan satu baris jurnal pada akun rekening itu
 * (journal_line_id unik: satu baris jurnal hanya untuk satu mutasi). amount bertanda:
 * positif = uang masuk ke rekening, negatif = uang keluar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 18, 2)->nullable();
            $table->decimal('closing_balance', 18, 2);
            $table->string('file_name', 255)->nullable();
            $table->string('status', 15)->default('open')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->date('line_date');
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance', 18, 2)->nullable();
            $table->foreignId('journal_line_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('match_type', 10)->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
