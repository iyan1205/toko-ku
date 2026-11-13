<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 16d: stok opname dengan pembekuan stok gudang selama dihitung dan pengajuan persetujuan
 * (status submitted) sebelum selisihnya dibukukan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->boolean('freeze_stock')->default(false)->after('status');
            $table->timestamp('submitted_at')->nullable()->after('freeze_stock');
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'status']);
            $table->dropColumn(['freeze_stock', 'submitted_at']);
        });
    }
};
