<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill of Materials (BOM) — resep per produk jual.
 * Satu produk jual bisa punya satu BOM aktif. Harga bahan dibaca langsung
 * dari produk bahan baku (supaya perubahan harga otomatis tercermin di perhitungan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->nullOnDelete();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['recipe_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
    }
};
