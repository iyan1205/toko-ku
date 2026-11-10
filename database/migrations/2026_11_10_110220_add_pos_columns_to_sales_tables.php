<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom Kasir Fase 6, semuanya tambahan dengan nilai bawaan yang sama dengan perilaku lama:
 *   - sales: sesi kasir, pajak (tarif, nilai, termasuk/belum termasuk harga), dan nilai retur.
 *     grand_total = subtotal - item_discount - discount (+ tax bila pajak belum termasuk harga).
 *   - sale_items: jumlah yang sudah diretur.
 *   - payments: sesi kasir tempat uang diterima atau dikembalikan (untuk rekap setoran).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount');
            $table->boolean('tax_inclusive')->default(false)->after('tax_rate');
            $table->decimal('tax', 15, 2)->default(0)->after('tax_inclusive');
            $table->decimal('returned_amount', 15, 2)->default(0)->after('balance');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 15, 3)->default(0)->after('quantity');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_session_id');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropColumn(['tax_rate', 'tax_inclusive', 'tax', 'returned_amount']);
        });
    }
};
