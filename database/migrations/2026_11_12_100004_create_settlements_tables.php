<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pelunasan piutang/hutang. Satu dokumen untuk:
 *   receipt     : penerimaan dari pelanggan (boleh untuk banyak faktur, sebagian, sisa jadi uang muka)
 *   payment     : pembayaran ke supplier (idem)
 *   credit_note : nota kredit ke pelanggan (mengurangi faktur tanpa uang)
 *   debit_note  : nota debit ke supplier
 *
 * Alokasi ke penjualan/faktur pembelian/faktur manual ada di settlement_allocations. Untuk
 * receipt/payment, setiap alokasi juga dicatat sebagai baris payments (payments.settlement_id),
 * sehingga riwayat pembayaran di dokumen dan saldo dokumen tetap lewat PaymentService.
 * Giro/cek mundur: method = giro dengan nomor, bank, jatuh tempo, dan status (pending/cleared/bounced).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->string('type', 15);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('settlement_date')->index();
            $table->string('method', 15)->nullable();
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('deposit_applied', 18, 2)->default(0);
            $table->decimal('deposit_added', 18, 2)->default(0);
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('giro_number', 50)->nullable();
            $table->string('giro_bank', 100)->nullable();
            $table->date('giro_due_date')->nullable()->index();
            $table->string('giro_status', 15)->nullable()->index();
            $table->date('giro_cleared_on')->nullable();
            $table->foreignId('giro_cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('reference', 50)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('posted')->index();
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

        Schema::create('settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->decimal('amount', 18, 2);
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['document_type', 'document_id'], 'settlement_allocations_document_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('settlement_id')->nullable()->after('cash_session_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('settlement_id'));
        Schema::dropIfExists('settlement_allocations');
        Schema::dropIfExists('settlements');
    }
};
