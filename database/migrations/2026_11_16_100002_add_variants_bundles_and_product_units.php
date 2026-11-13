<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 16b: varian produk, produk paket, dan multi-satuan.
 *
 *   products.parent_id / variant_values   varian = produk tersendiri (SKU, barcode, stok, harga) di bawah induk
 *   products.variant_options              atribut varian induk, misal [{name: Ukuran, values: [S, M, L]}]
 *   products.is_bundle                    paket: menjual paket mengurangi stok komponen (resep/BOM produk itu)
 *   product_units                         satuan lain dengan konversi ke satuan dasar, barcode & harga per satuan
 *   sale_items.sale_unit_*                satuan yang dipakai saat menjual (quantity tetap dalam satuan dasar)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('products')->nullOnDelete();
            $table->json('variant_options')->nullable()->after('description');
            $table->json('variant_values')->nullable()->after('variant_options');
            $table->boolean('is_bundle')->default(false)->after('track_serials');
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('factor', 15, 4); // isi satuan dasar per satu satuan ini
            $table->string('barcode', 50)->nullable();
            $table->decimal('selling_price', 18, 2)->nullable(); // kosong = harga dasar × isi
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
            $table->index('barcode');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('sale_unit_id')->nullable()->after('unit_name')->constrained('units')->nullOnDelete();
            $table->string('sale_unit_name', 30)->nullable()->after('sale_unit_id');
            $table->decimal('sale_unit_factor', 15, 4)->nullable()->after('sale_unit_name');
            $table->decimal('sale_unit_quantity', 15, 3)->nullable()->after('sale_unit_factor');
            $table->decimal('sale_unit_price', 18, 2)->nullable()->after('sale_unit_quantity');
        });

        // Satu produk boleh dua baris bila satuannya berbeda (2 DUS + 3 PCS). Indeks baru dibuat dulu
        // (diawali sale_id, tetap melayani foreign key), baru indeks lama dilepas. Tidak ada data yang dihapus.
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unique(['sale_id', 'product_id', 'sale_unit_id'], 'sale_items_sale_product_unit_unique');
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unique(['sale_id', 'product_id']);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique('sale_items_sale_product_unit_unique');
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_unit_id');
            $table->dropColumn(['sale_unit_name', 'sale_unit_factor', 'sale_unit_quantity', 'sale_unit_price']);
        });
        Schema::dropIfExists('product_units');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['variant_options', 'variant_values', 'is_bundle']);
        });
    }
};
