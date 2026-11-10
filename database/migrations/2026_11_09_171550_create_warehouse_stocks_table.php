<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stok per gudang. products.stock tetap ada sebagai total semua gudang dan selalu
 * diubah bersamaan oleh StockService. Baris dibuat saat produk pertama kali punya
 * mutasi di gudang itu; produk tanpa baris berarti stoknya 0 di gudang tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
            $table->index('product_id');
        });

        // Stok yang sudah ada sebelum multi-gudang berada di Gudang Utama.
        $warehouseId = DB::table('warehouses')->where('is_default', true)->value('id');
        $now = now();

        DB::table('products')->where('stock', '!=', 0)->orderBy('id')->select(['id', 'stock'])
            ->chunkById(500, function ($products) use ($warehouseId, $now) {
                DB::table('warehouse_stocks')->insert($products->map(fn ($product) => [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $product->id,
                    'quantity' => $product->stock,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
