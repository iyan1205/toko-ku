<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase order: draft → disetujui → diterima sebagian → selesai (atau dibatalkan).
 * PO tidak mengubah stok atau hutang; keduanya dicatat saat barang diterima (goods_receipts).
 *
 * Arti angka sama dengan penjualan: subtotal = sebelum diskon per baris,
 * grand_total = subtotal - item_discount - discount + tax + other_cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete(); // gudang tujuan bawaan
            $table->date('order_date')->index();
            $table->date('expected_date')->nullable();
            $table->string('status', 15)->default('draft')->index();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('item_discount', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('other_cost', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable(); // ditutup walau belum diterima semua
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'order_date']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->string('sku', 50);
            $table->string('unit_name', 50);

            $table->decimal('quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->default(0);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount', 15, 2)->default(0); // nominal diskon untuk seluruh baris
            $table->decimal('line_total', 15, 2);           // jumlah x harga - diskon
            $table->timestamps();

            $table->unique(['purchase_order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
