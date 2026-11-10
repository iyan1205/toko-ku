<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Console\Command;

/**
 * Periksa konsistensi stok (hanya membaca). Tiga aturan yang dijaga StockService:
 *   1. products.stock = jumlah seluruh mutasi produk itu (semua gudang),
 *   2. products.stock = jumlah stok produk itu di semua gudang (warehouse_stocks),
 *   3. per gudang: stok gudang = jumlah mutasinya di gudang itu = saldo mutasi terakhirnya.
 */
class PeriksaStok extends Command
{
    protected $signature = 'stok:periksa';

    protected $description = 'Periksa konsistensi stok total dan per gudang terhadap riwayat mutasi (hanya membaca, tidak mengubah data)';

    private const TOLERANSI = 0.0005;

    public function handle(): int
    {
        $masalah = $this->checkTotals() + $this->checkWarehouses();

        if ($masalah > 0) {
            $this->error("{$masalah} ketidakcocokan ditemukan. Periksa riwayat stok sebelum melanjutkan.");

            return self::FAILURE;
        }

        $this->info('Semua stok konsisten dengan riwayat mutasinya, baik total maupun per gudang.');

        return self::SUCCESS;
    }

    /** Aturan 1 dan 2: stok total produk. */
    private function checkTotals(): int
    {
        $mutasi = StockMovement::selectRaw('product_id, SUM(quantity) as total')->groupBy('product_id')->pluck('total', 'product_id');
        $gudang = WarehouseStock::selectRaw('product_id, SUM(quantity) as total')->groupBy('product_id')->pluck('total', 'product_id');
        $masalah = 0;

        Product::withTrashed()->orderBy('id')->chunkById(500, function ($produk) use ($mutasi, $gudang, &$masalah) {
            foreach ($produk as $p) {
                $stok = round((float) $p->stock, 3);
                $totalMutasi = round((float) ($mutasi[$p->id] ?? 0), 3);
                $totalGudang = round((float) ($gudang[$p->id] ?? 0), 3);

                if (abs($stok - $totalMutasi) > self::TOLERANSI || abs($stok - $totalGudang) > self::TOLERANSI) {
                    $masalah++;
                    $this->error(sprintf(
                        'TIDAK COCOK  %s (%s): stok produk %s, jumlah mutasi %s, jumlah stok semua gudang %s',
                        $p->sku, $p->name, $stok, $totalMutasi, $totalGudang
                    ));
                }
            }
        });

        return $masalah;
    }

    /** Aturan 3: setiap pasangan gudang × produk yang punya stok atau mutasi. */
    private function checkWarehouses(): int
    {
        $kunci = fn ($warehouseId, $productId) => "{$warehouseId}:{$productId}";

        $mutasi = StockMovement::selectRaw('warehouse_id, product_id, SUM(quantity) as total')
            ->groupBy('warehouse_id', 'product_id')->get()
            ->mapWithKeys(fn ($r) => [$kunci($r->warehouse_id, $r->product_id) => (float) $r->total]);

        $terakhir = StockMovement::whereIn('id', StockMovement::selectRaw('MAX(id)')->groupBy('warehouse_id', 'product_id'))
            ->get(['warehouse_id', 'product_id', 'stock_after'])
            ->mapWithKeys(fn ($r) => [$kunci($r->warehouse_id, $r->product_id) => (float) $r->stock_after]);

        $stok = WarehouseStock::query()->get(['warehouse_id', 'product_id', 'quantity'])
            ->mapWithKeys(fn ($r) => [$kunci($r->warehouse_id, $r->product_id) => (float) $r->quantity]);

        $namaGudang = Warehouse::withTrashed()->pluck('name', 'id');
        $produk = Product::withTrashed()->get(['id', 'sku', 'name'])->keyBy('id');
        $masalah = 0;

        foreach ($stok->keys()->merge($mutasi->keys())->unique()->sort() as $pasangan) {
            [$warehouseId, $productId] = explode(':', $pasangan);
            $qty = round($stok[$pasangan] ?? 0, 3);
            $total = round($mutasi[$pasangan] ?? 0, 3);
            $akhir = round($terakhir[$pasangan] ?? 0, 3);

            if (abs($qty - $total) > self::TOLERANSI || abs($qty - $akhir) > self::TOLERANSI) {
                $masalah++;
                $p = $produk[(int) $productId] ?? null;
                $this->error(sprintf(
                    'TIDAK COCOK  %s (%s) di %s: stok gudang %s, jumlah mutasi %s, saldo mutasi terakhir %s',
                    $p?->sku ?? "#{$productId}", $p?->name ?? '?', $namaGudang[(int) $warehouseId] ?? ($warehouseId === '' ? 'tanpa gudang' : "gudang #{$warehouseId}"),
                    $qty, $total, $akhir
                ));
            }
        }

        return $masalah;
    }
}
