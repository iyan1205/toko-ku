<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemecahan bahan (satu bahan → beberapa produk sekaligus), misal 1 pack Karkas 1 dipotong menjadi
 * 3 dada, 2 paha atas, 2 paha bawah, 2 sayap. Biaya bahan dibagi ke hasilnya (rata per satuan hasil,
 * atau sebanding harga jual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->decimal('source_quantity', 15, 4)->default(1); // jumlah bahan per sekali pecah (misal 1 pack)
            $table->string('cost_method', 10)->default('quantity'); // quantity | price
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_breakdown_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_breakdown_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4); // hasil per sekali pecah
            $table->unique(['product_breakdown_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_breakdown_outputs');
        Schema::dropIfExists('product_breakdowns');
    }
};
