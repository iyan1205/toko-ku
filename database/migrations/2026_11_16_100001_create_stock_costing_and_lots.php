<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 16a: biaya persediaan (FIFO / rata-rata tertimbang), batch/lot dengan tanggal kedaluwarsa,
 * dan nomor seri.
 *
 *   stock_lots            lapisan stok per gudang (satu per stok masuk): batch, kedaluwarsa, biaya per unit, sisa
 *   stock_movement_lots   lot mana yang dipakai/diisi oleh satu mutasi (untuk pembalik dan kartu stok per batch)
 *   stock_movements       + unit_cost/total_cost (bertanda, sama dengan quantity)
 *   warehouse_stocks      + value (nilai persediaan gudang itu)
 *
 * Stok yang sudah ada dijadikan satu lot awal per gudang dengan biaya = harga beli produk saat ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_batches')->default(false)->after('min_stock');
            $table->boolean('track_serials')->default(false)->after('track_batches');
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->dateTime('received_at');
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('quantity_in', 15, 3);
            $table->decimal('quantity', 15, 3); // sisa
            $table->unsignedBigInteger('source_movement_id')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'warehouse_id', 'quantity']);
            $table->index('expiry_date');
            $table->index(['product_id', 'batch_number']);
        });

        Schema::create('stock_movement_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 15, 3); // bertanda
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('reversed_quantity', 15, 3)->default(0); // sudah dibalik (retur/pembatalan)
            $table->index('stock_lot_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 2)->nullable()->after('stock_after');
            $table->decimal('total_cost', 18, 2)->nullable()->after('unit_cost');
        });

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->decimal('value', 18, 2)->default(0)->after('quantity');
        });

        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('serial_number', 100);
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('in_stock'); // in_stock | out
            $table->unsignedBigInteger('in_movement_id')->nullable();
            $table->unsignedBigInteger('out_movement_id')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'serial_number']);
            $table->index(['product_id', 'warehouse_id', 'status']);
        });

        // Lot awal dari stok yang ada sekarang (biaya = harga beli produk).
        $now = now();
        DB::table('warehouse_stocks')
            ->join('products', 'products.id', '=', 'warehouse_stocks.product_id')
            ->where('warehouse_stocks.quantity', '!=', 0)
            ->orderBy('warehouse_stocks.id')
            ->select(['warehouse_stocks.id', 'warehouse_stocks.warehouse_id', 'warehouse_stocks.product_id', 'warehouse_stocks.quantity', 'products.purchase_price'])
            ->chunk(500, function ($rows) use ($now) {
                foreach ($rows as $row) {
                    $qty = (float) $row->quantity;
                    $cost = (float) $row->purchase_price;
                    DB::table('warehouse_stocks')->where('id', $row->id)->update(['value' => round($qty * $cost, 2)]);
                    if ($qty > 0) {
                        DB::table('stock_lots')->insert([
                            'product_id' => $row->product_id, 'warehouse_id' => $row->warehouse_id, 'received_at' => $now,
                            'unit_cost' => $cost, 'quantity_in' => $qty, 'quantity' => $qty, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
        Schema::table('warehouse_stocks', fn (Blueprint $table) => $table->dropColumn('value'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn(['unit_cost', 'total_cost']));
        Schema::dropIfExists('stock_movement_lots');
        Schema::dropIfExists('stock_lots');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['track_batches', 'track_serials']));
    }
};
