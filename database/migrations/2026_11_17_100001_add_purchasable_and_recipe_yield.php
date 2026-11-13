<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produk aktif ≠ produk dijual: products.type tetap menandai dijual di kasir (jual) atau bahan baku,
 * ditambah is_purchasable (bisa dibeli dari supplier; produk olahan sendiri tidak). Resep punya hasil
 * per resep (misal 1 pack karkas → 9 potong), dipakai saat produksi mengurangi stok bahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_purchasable')->default(true)->after('type');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->decimal('yield_quantity', 15, 4)->default(1)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', fn (Blueprint $table) => $table->dropColumn('yield_quantity'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_purchasable'));
    }
};
