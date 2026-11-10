<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gudang di kartu stok dan di dokumen yang mengubah stok (penjualan, pembelian, opname),
 * supaya pembatalan mengembalikan stok ke gudang yang benar. Data lama diisi Gudang Utama.
 *
 * Stok opname kini punya status: draft (hitungan disimpan, stok belum berubah) dan
 * final (selisih sudah dibukukan). Opname lama langsung dibukukan, jadi berstatus final.
 */
return new class extends Migration
{
    private const TABLES = ['stock_movements', 'sales', 'purchases', 'stock_opnames'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('warehouse_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->string('status', 10)->default('final')->after('note')->index();
            $table->timestamp('finalized_at')->nullable()->after('status');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->restrictOnDelete();
        });

        $warehouseId = DB::table('warehouses')->where('is_default', true)->value('id');
        foreach (self::TABLES as $name) {
            DB::table($name)->whereNull('warehouse_id')->update(['warehouse_id' => $warehouseId]);
        }

        // Opname lama dibukukan saat disimpan.
        DB::table('stock_opnames')->whereNull('finalized_at')
            ->update(['finalized_at' => DB::raw('created_at'), 'finalized_by' => DB::raw('user_id')]);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['warehouse_id', 'product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'product_id', 'id']);
        });

        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['status', 'finalized_at']);
        });

        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('warehouse_id');
            });
        }
    }
};
