<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur pembelian: barang dari satu faktur pembelian dikembalikan ke supplier.
 * Draft belum berpengaruh apa pun. Saat disetujui: stok keluar dari gudang yang dipilih
 * dan hutang faktur berkurang. Bila nilai retur melebihi sisa hutang (barang sudah dibayar),
 * kelebihannya dicatat di refund_amount sebagai uang yang harus dikembalikan supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('return_date')->index();
            $table->string('reason', 255);
            $table->string('status', 10)->default('draft')->index(); // draft | approved

            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('refund_amount', 15, 2)->default(0);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->string('sku', 50);
            $table->string('unit_name', 50);

            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2); // harga bersih per unit di faktur
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            // Nama eksplisit: nama bawaan Laravel tepat 64 karakter, batas maksimal MySQL.
            $table->unique(['purchase_return_id', 'purchase_item_id'], 'purchase_return_items_return_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
