<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penerimaan barang dari purchase order (boleh sebagian). Setiap penerimaan menambah stok
 * gudang tujuan dan membuat satu faktur pembelian (purchases) berisi hutang ke supplier
 * sebesar nilai barang yang diterima.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Percobaan pertama di MySQL gagal di tengah (nama index terlalu panjang) dan DDL MySQL tidak
        // bisa di-rollback, sehingga kedua tabel sempat dibuat. Sisa itu dihapus hanya bila masih kosong;
        // bila sudah berisi data, Schema::create() di bawah gagal dan tidak ada yang dihapus.
        foreach (['goods_receipt_items', 'goods_receipts'] as $sisa) {
            if (Schema::hasTable($sisa) && DB::table($sisa)->doesntExist()) {
                Schema::drop($sisa);
            }
        }

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete(); // faktur/hutang
            $table->date('received_date')->index();
            $table->string('delivery_note_number', 50)->nullable(); // nomor surat jalan supplier
            $table->string('note', 255)->nullable();
            $table->string('status', 10)->default('completed')->index(); // completed | voided
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->string('sku', 50);
            $table->string('unit_name', 50);

            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount', 15, 2)->default(0); // bagian diskon baris PO untuk jumlah ini
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            // Nama eksplisit: nama bawaan Laravel (66 karakter) melebihi batas MySQL 64 karakter.
            $table->unique(['goods_receipt_id', 'purchase_order_item_id'], 'goods_receipt_items_receipt_po_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
