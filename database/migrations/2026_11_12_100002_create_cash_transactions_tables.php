<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi kas & bank di luar penjualan/pembelian: penerimaan (in), pengeluaran (out), dan
 * transfer antar rekening (transfer, termasuk pengisian ulang kas kecil). Status baku
 * draft → submitted → approved → posted (atau cancelled). Saat diposting dibuat satu jurnal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->string('type', 10);
            $table->date('transaction_date')->index();
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('counterparty', 150)->nullable();
            $table->string('reference', 50)->nullable();
            $table->string('description', 255);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('fee', 18, 2)->default(0);
            $table->foreignId('fee_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cash_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transaction_lines');
        Schema::dropIfExists('cash_transactions');
    }
};
