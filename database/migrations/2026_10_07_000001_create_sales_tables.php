<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // kasir
            $table->timestamp('sold_at')->index();

            // subtotal = jumlah x harga seluruh baris (sebelum diskon).
            // grand_total = subtotal - item_discount - discount. Seluruh uang decimal, tidak pernah float.
            $table->decimal('subtotal', 15, 2);
            $table->decimal('item_discount', 15, 2)->default(0);   // total diskon per baris
            $table->decimal('discount', 15, 2)->default(0);        // diskon transaksi
            $table->decimal('grand_total', 15, 2);

            $table->decimal('paid_amount', 15, 2)->default(0);          // yang sudah dibayar (bukan uang yang diserahkan)
            $table->decimal('initial_paid_amount', 15, 2)->default(0);  // dibayar saat transaksi; selisihnya = pelunasan susulan
            $table->decimal('balance', 15, 2);                          // sisa piutang
            $table->decimal('tendered_amount', 15, 2)->default(0);      // uang yang diserahkan pelanggan
            $table->decimal('change_amount', 15, 2)->default(0);        // kembalian

            $table->string('payment_method', 20)->index();
            $table->string('payment_status', 10)->index(); // paid | unpaid | partial
            $table->string('status', 10)->default('completed')->index(); // completed | voided
            $table->string('note', 255)->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sold_at']);
            $table->index(['customer_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->string('product_name', 150);
            $table->string('sku', 50);
            $table->string('unit_name', 50);

            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);  // harga jual SAAT TRANSAKSI
            $table->decimal('cost_price', 15, 2);  // harga modal SAAT TRANSAKSI: dasar laporan keuntungan historis
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);  // jumlah x harga - diskon
            $table->timestamps();

            $table->unique(['sale_id', 'product_id']);
        });

        // Transaksi yang ditahan (F9). Sementara, bukan transaksi keuangan, jadi boleh dihapus setelah selesai.
        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 60)->nullable();
            $table->json('cart');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_sales');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
