<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faktur pembelian (purchases) tetap menjadi catatan hutang, kini bisa berasal dari PO.
 *
 * Arti subtotal disamakan dengan penjualan (risiko #22 di docs/AUDIT.md):
 * sebelumnya subtotal = jumlah baris SETELAH diskon baris; kini subtotal = SEBELUM diskon
 * baris, dan diskon baris dicatat di item_discount. Grand total tidak berubah:
 * grand_total = subtotal - item_discount - discount + tax + other_cost.
 *
 * returned_amount = nilai retur yang sudah disetujui; purchase_items.returned_quantity
 * menjaga agar barang tidak diretur melebihi jumlah yang dibeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained()->restrictOnDelete();
            $table->decimal('item_discount', 15, 2)->default(0)->after('subtotal');
            $table->decimal('returned_amount', 15, 2)->default(0)->after('paid_amount');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 15, 3)->default(0)->after('quantity');
        });

        DB::table('purchases')->update([
            'item_discount' => DB::raw('(SELECT COALESCE(SUM(purchase_items.discount), 0) FROM purchase_items WHERE purchase_items.purchase_id = purchases.id)'),
        ]);
        DB::table('purchases')->update(['subtotal' => DB::raw('subtotal + item_discount')]);
    }

    public function down(): void
    {
        DB::table('purchases')->update(['subtotal' => DB::raw('subtotal - item_discount')]);

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn(['item_discount', 'returned_amount']);
        });
    }
};
