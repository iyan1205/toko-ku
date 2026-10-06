<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_number', 50)->nullable()->index();
            $table->date('purchase_date')->index();
            $table->date('due_date')->nullable()->index();

            // Seluruh uang decimal, tidak pernah float. Diskon, pajak, biaya lain berupa nominal Rupiah.
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('other_cost', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('balance', 15, 2);

            $table->string('payment_status', 10)->index();   // paid | unpaid | partial
            $table->string('status', 10)->default('completed')->index(); // completed | voided
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Pembatalan (void): tercatat siapa, kapan, dan alasannya. Baris tidak pernah dihapus.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'purchase_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Salinan saat transaksi: riwayat tetap akurat walau produk kelak diganti nama atau satuannya.
            $table->string('product_name', 150);
            $table->string('sku', 50);
            $table->string('unit_name', 50);

            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);   // harga beli per unit saat transaksi
            $table->decimal('discount', 15, 2)->default(0); // nominal diskon untuk seluruh baris
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->unique(['purchase_id', 'product_id']);
        });

        // Catatan setiap uang yang keluar/masuk. Dipakai pembelian (bayar supplier) sekarang,
        // dan penjualan (terima dari pelanggan) pada tahap piutang.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('payable_type', 30);
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 15, 2);
            $table->string('method', 20);
            $table->date('paid_at');
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
