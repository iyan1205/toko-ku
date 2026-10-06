<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class PeriksaStok extends Command
{
    protected $signature = 'stok:periksa';

    protected $description = 'Periksa konsistensi stok: stok produk harus sama dengan jumlah seluruh mutasinya (hanya membaca, tidak mengubah data)';

    public function handle(): int
    {
        $jumlah = StockMovement::selectRaw('product_id, SUM(quantity) as total')->groupBy('product_id')->pluck('total', 'product_id');
        $terakhir = StockMovement::whereIn('id', StockMovement::selectRaw('MAX(id)')->groupBy('product_id'))->pluck('stock_after', 'product_id');

        $masalah = 0;

        Product::withTrashed()->orderBy('id')->chunkById(500, function ($produk) use ($jumlah, $terakhir, &$masalah) {
            foreach ($produk as $p) {
                $stok = round((float) $p->stock, 3);
                $total = round((float) ($jumlah[$p->id] ?? 0), 3);
                $akhir = isset($terakhir[$p->id]) ? round((float) $terakhir[$p->id], 3) : 0.0;

                if (abs($stok - $total) > 0.0005 || abs($stok - $akhir) > 0.0005) {
                    $masalah++;
                    $this->error(sprintf(
                        'TIDAK COCOK  %s (%s): stok produk %s, jumlah mutasi %s, saldo mutasi terakhir %s',
                        $p->sku, $p->name, $stok, $total, $akhir
                    ));
                }
            }
        });

        if ($masalah > 0) {
            $this->error("{$masalah} produk tidak konsisten. Periksa riwayat stok sebelum melanjutkan.");

            return self::FAILURE;
        }

        $this->info('Semua stok konsisten dengan riwayat mutasinya.');

        return self::SUCCESS;
    }
}
