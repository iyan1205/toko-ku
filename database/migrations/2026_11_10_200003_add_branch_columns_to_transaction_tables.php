<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * company_id + branch_id di semua tabel transaksi (trait BelongsToBranch).
 *
 * Kolom dibuat nullable supaya aman ditambahkan ke tabel berisi data; aplikasi selalu
 * mengisinya. Data lama diisi dari:
 *   - cabang gudangnya (dokumen yang punya gudang),
 *   - cabang dokumen asalnya (pembayaran → penjualan/pembelian/retur, jurnal otomatis → dokumen sumber),
 *   - selain itu cabang utama.
 */
return new class extends Migration
{
    /** Tabel => kolom gudang yang menentukan cabang (null = dari dokumen asal). */
    private const TABLES = [
        'sales' => 'warehouse_id',
        'sale_returns' => 'warehouse_id',
        'purchases' => 'warehouse_id',
        'purchase_orders' => 'warehouse_id',
        'goods_receipts' => 'warehouse_id',
        'purchase_returns' => 'warehouse_id',
        'stock_movements' => 'warehouse_id',
        'stock_opnames' => 'warehouse_id',
        'stock_transfers' => 'from_warehouse_id',
        'cash_sessions' => 'warehouse_id',
        'payments' => null,
        'journals' => null,
    ];

    /** Alias morph (AppServiceProvider) => tabel, untuk payments.payable_* dan journals.source_*. */
    private const SOURCES = [
        'sale' => 'sales',
        'purchase' => 'purchases',
        'sale_return' => 'sale_returns',
        'purchase_return' => 'purchase_returns',
        'goods_receipt' => 'goods_receipts',
        'stock_opname' => 'stock_opnames',
        'stock_transfer' => 'stock_transfers',
        'payment' => 'payments',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();

                if (! Schema::hasColumn($name, 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('company_id')->constrained()->restrictOnDelete();
                }
            });
        }

        $defaultBranch = DB::table('branches')->where('is_default', true)->value('id');
        $defaultCompany = DB::table('companies')->where('is_default', true)->value('id');

        // 1. Dari cabang gudang.
        foreach (self::TABLES as $name => $warehouseColumn) {
            if ($warehouseColumn !== null) {
                DB::table($name)->whereNull('branch_id')->update([
                    'branch_id' => DB::raw("(select warehouses.branch_id from warehouses where warehouses.id = {$name}.{$warehouseColumn})"),
                ]);
            }
        }

        // 2. Pembayaran dari dokumen yang dibayar; lalu jurnal dari dokumen sumbernya (termasuk pembayaran).
        foreach (['payments' => 'payable', 'journals' => 'source'] as $name => $morph) {
            foreach (self::SOURCES as $alias => $sourceTable) {
                if ($sourceTable === $name) {
                    continue;
                }
                DB::table($name)->whereNull('branch_id')->where("{$morph}_type", $alias)->update([
                    'branch_id' => DB::raw("(select {$sourceTable}.branch_id from {$sourceTable} where {$sourceTable}.id = {$name}.{$morph}_id)"),
                ]);
            }
        }

        // 3. Sisanya (gudang tanpa cabang, jurnal manual) ke cabang utama; perusahaan dari cabangnya.
        foreach (array_keys(self::TABLES) as $name) {
            DB::table($name)->whereNull('branch_id')->update(['branch_id' => $defaultBranch]);
            DB::table($name)->whereNull('company_id')->update(['company_id' => $defaultCompany]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropConstrainedForeignId('company_id');

                if ($name !== 'cash_sessions') {
                    $table->dropConstrainedForeignId('branch_id');
                }
            });
        }
    }
};
