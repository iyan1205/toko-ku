<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur Pecah Bahan dihapus atas permintaan pemilik (14 Nov 2026): potongan dari bahan (misal karkas →
 * dada, paha) cukup lewat resep produksi masing-masing produk. Tabelnya belum pernah berisi data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_breakdown_outputs');
        Schema::dropIfExists('product_breakdowns');
    }

    public function down(): void
    {
        Schema::create('product_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->decimal('source_quantity', 15, 4)->default(1);
            $table->string('cost_method', 10)->default('quantity');
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
            $table->decimal('quantity', 15, 4);
            $table->unique(['product_breakdown_id', 'product_id']);
            $table->index('product_id');
        });
    }
};
