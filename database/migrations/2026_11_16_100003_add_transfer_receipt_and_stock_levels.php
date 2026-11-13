<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 16c: transfer antar cabang dua langkah (dikirim → dalam perjalanan → diterima) dan stok
 * minimum/maksimum per gudang untuk saran pemesanan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('status');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->string('receive_note', 255)->nullable()->after('received_by');
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->decimal('received_quantity', 15, 3)->nullable()->after('quantity');
        });

        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->decimal('min_stock', 15, 3)->nullable()->after('value'); // kosong = stok minimum produk
            $table->decimal('max_stock', 15, 3)->nullable()->after('min_stock');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stocks', fn (Blueprint $table) => $table->dropColumn(['min_stock', 'max_stock']));
        Schema::table('stock_transfer_items', fn (Blueprint $table) => $table->dropColumn('received_quantity'));
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['received_at', 'receive_note']);
        });
    }
};
