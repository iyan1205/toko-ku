<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\StockService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Produksi produk olahan dari resepnya: stok bahan berkurang (jumlah × bahan per resep ÷ hasil resep),
 * stok produk bertambah dengan biaya = biaya bahan yang terpakai. Contoh: resep Ayam Original Dada =
 * Karkas 1 sebanyak 1 pack, hasil 9 → produksi 9 dada memakai 1 pack karkas; 2 dada memakai 0,222 pack.
 *
 * Nilai persediaan hanya pindah dari bahan ke produk jadi (tidak ada jurnal laba/rugi). Semua dalam satu
 * transaksi: bila satu bahan kurang, produksi dibatalkan seluruhnya.
 */
class ProductionService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @return Collection<int, StockMovement> mutasi bahan (keluar) lalu mutasi produk (masuk)
     */
    public function produce(Product $produk, float|int|string $jumlah, string $catatan, User $user, ?Warehouse $gudang = null): Collection
    {
        $produk->loadMissing('recipe.items.product');
        if (! $produk->isProduced()) {
            throw new AturanBisnisException("{$produk->name} belum punya resep berbahan. Atur resepnya dulu di menu Resep/BOM.");
        }

        $milli = Uang::milli($jumlah);
        if ($milli <= 0) {
            throw new AturanBisnisException('Jumlah produksi harus lebih dari nol.');
        }

        app(FiscalPeriodService::class)->ensureOpen(now());
        $gudang ??= $this->stock->defaultWarehouse();
        $hasil = $produk->recipe->yieldQuantity();
        $keterangan = trim("Produksi {$produk->name} ".Format::jumlah($milli / 1000).($catatan !== '' ? ": {$catatan}" : ''));

        return DB::transaction(function () use ($produk, $milli, $hasil, $keterangan, $user, $gudang) {
            $mutasi = collect();
            $biaya = 0;

            foreach ($produk->recipe->items->sortBy('product_id') as $item) {
                if ($item->product === null || (float) $item->quantity <= 0) {
                    continue;
                }
                $pakai = (int) round($milli * (float) $item->quantity / $hasil);
                if ($pakai <= 0) {
                    continue;
                }
                $keluar = $this->stock->catat($item->product, StockMovementType::Production, '-'.Uang::milliKeDesimal($pakai), null, $keterangan, $user, $gudang);
                $biaya += abs(Uang::sen($keluar->total_cost));
                $mutasi->push($keluar);
            }

            $mutasi->push($this->stock->catat($produk, StockMovementType::Production, Uang::milliKeDesimal($milli), null, $keterangan, $user, $gudang,
                ['unit_cost' => Uang::perUnit($biaya, $milli)]));

            activity('inventori')->performedOn($produk)->causedBy($user)->event('produced')
                ->withProperties(['jumlah' => Uang::milliKeDesimal($milli), 'gudang' => $gudang->name, 'biaya' => Uang::keDesimal($biaya)])
                ->log($keterangan);

            return $mutasi;
        });
    }
}
