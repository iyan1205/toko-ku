<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faktur manual (non-stok): faktur penjualan (piutang) dan tagihan pembelian (hutang) untuk jasa,
 * sewa, biaya, dan sejenisnya, plus faktur saldo awal (kind = opening, dibuat saat saldo awal
 * diposting; jurnalnya ikut jurnal saldo awal). Bersama penjualan kasir dan faktur pembelian,
 * ini dokumen yang dilunasi lewat Pelunasan (settlements).
 *
 * Juga: jatuh tempo penjualan piutang (sales.due_date, diisi dari termin pelanggan), termin
 * pembayaran pelanggan/supplier, dan nilai nota kredit/debit (credited_amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10);
            $table->string('kind', 10)->default('manual');
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->string('reference', 50)->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('credited_amount', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('opening_balance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status', 'balance']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('sold_at')->index();
            $table->decimal('credited_amount', 18, 2)->default(0)->after('returned_amount');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('credited_amount', 18, 2)->default(0)->after('returned_amount');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('receivable_balance');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('npwp');
        });

        // Penjualan piutang lama: jatuh tempo 30 hari dari tanggal transaksi.
        foreach (DB::table('sales')->where('payment_method', 'receivable')->whereNull('due_date')->get(['id', 'sold_at']) as $sale) {
            DB::table('sales')->where('id', $sale->id)->update(['due_date' => date('Y-m-d', strtotime(substr((string) $sale->sold_at, 0, 10).' +30 days'))]);
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('credited_amount'));
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
            $table->dropColumn(['due_date', 'credited_amount']);
        });
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
