<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Tambah kolom 'type' ke products: 'jual' (default) atau 'bahan_baku'.
 * Bahan baku tidak tampil di kasir dan daftar produk, tapi tetap ada untuk BOM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('type', 15)->default('jual')->after('name')->index();
        });

        // Set type='bahan_baku' untuk kategori Bahan Baku dan Operasional.
        $bahanKategori = DB::table('categories')->whereIn('name', ['Bahan Baku', 'Operasional'])->pluck('id');
        if ($bahanKategori->isNotEmpty()) {
            DB::table('products')->whereIn('category_id', $bahanKategori)->update(['type' => 'bahan_baku']);
        }

        // Produk VRN-* (Nasi, Kulit, Kentang, Sosis, Burger) juga bahan baku.
        DB::table('products')->where('sku', 'LIKE', 'VRN-%')->update(['type' => 'bahan_baku']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
