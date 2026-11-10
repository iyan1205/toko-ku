<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur penjualan atas satu transaksi kasir.
 *   - draft: diajukan, belum berpengaruh apa pun.
 *   - approved: stok kembali ke gudang penjualan, piutang transaksi berkurang dulu, sisanya
 *     dikembalikan ke pelanggan (refund_amount, dicatat juga sebagai baris payments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // yang mengajukan
            $table->date('return_date')->index();
            $table->string('reason', 255);
            $table->string('status', 10)->default('draft')->index(); // draft | approved

            $table->decimal('total', 15, 2)->default(0);                 // nilai barang yang diretur
            $table->decimal('receivable_reduction', 15, 2)->default(0);  // bagian yang mengurangi piutang
            $table->decimal('refund_amount', 15, 2)->default(0);         // bagian yang dikembalikan ke pelanggan
            $table->string('refund_method', 20)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->string('sku', 50);
            $table->string('unit_name', 50);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2); // nilai bersih per unit (setelah diskon dan pajak transaksi)
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            // Nama eksplisit: aman di bawah batas 64 karakter MySQL.
            $table->unique(['sale_return_id', 'sale_item_id'], 'sale_return_items_return_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
