<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('barcode', 50)->nullable()->unique();
            $table->string('name', 150)->index();

            // restrictOnDelete: kategori/satuan yang masih dipakai produk tidak bisa terhapus.
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();

            // Uang selalu decimal, tidak pernah float.
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);

            // Stok hanya boleh berubah lewat stock_movements (Tahap 3). 3 desimal untuk KG/LITER.
            $table->decimal('stock', 15, 3)->default(0);
            $table->decimal('min_stock', 15, 3)->default(0);

            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('photo')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
