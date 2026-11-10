<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi/transfer stok antar gudang. Langsung selesai saat disimpan: stok keluar dari
 * gudang asal dan masuk ke gudang tujuan dalam satu transaksi. Kesalahan dibatalkan (void),
 * dokumen tidak pernah dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('transfer_date')->index();
            $table->string('note', 255)->nullable();
            $table->string('status', 10)->default('completed')->index(); // completed | voided
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Salinan saat transaksi, seperti di rincian pembelian/penjualan.
            $table->string('product_name', 150);
            $table->string('sku', 50);
            $table->string('unit_name', 50);
            $table->decimal('quantity', 15, 3);
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
