<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA pintu untuk mengubah stok (warehouse_stocks dan products.stock).
 *
 * Setiap perubahan, dalam satu transaksi database:
 *   1. kunci baris produk, lalu baris stok gudangnya (urutan selalu sama: mencegah deadlock),
 *   2. tolak bila stok GUDANG itu menjadi minus (kecuali pos.allow_negative_stock),
 *   3. ubah stok gudang dan products.stock (total semua gudang) bersamaan,
 *   4. tulis kartu stok (stock_movements) dengan gudangnya. stock_before/stock_after = stok gudang.
 *
 * Pemanggil yang tidak menyebut gudang memakai gudang utama.
 */
class StockService
{
    private ?Warehouse $defaultWarehouse = null;

    /**
     * @param  float|int|string  $jumlah  perubahan BERTANDA: positif = masuk, negatif = keluar
     */
    public function catat(
        Product $produk,
        StockMovementType $jenis,
        float|int|string $jumlah,
        ?Model $referensi = null,
        ?string $catatan = null,
        ?User $user = null,
        ?Warehouse $gudang = null,
    ): StockMovement {
        $delta = round((float) $jumlah, 3);

        if ($delta == 0.0) {
            throw new AturanBisnisException('Jumlah perubahan stok tidak boleh nol.');
        }

        $gudang ??= $this->defaultWarehouse();

        return DB::transaction(function () use ($produk, $jenis, $delta, $referensi, $catatan, $user, $gudang) {
            // Kunci baris produk: dua kasir yang menjual barang yang sama tidak boleh saling menimpa.
            $terkunci = Product::withTrashed()->whereKey($produk->getKey())->lockForUpdate()->firstOrFail();
            $stokGudang = $this->lockWarehouseStock($gudang, $terkunci);

            $sebelum = round((float) $stokGudang->quantity, 3);
            $sesudah = round($sebelum + $delta, 3);

            if ($sesudah < 0 && ! config('pos.allow_negative_stock')) {
                throw new AturanBisnisException(sprintf(
                    'Stok tidak mencukupi. %s di %s: tersedia %s, diminta %s.',
                    $terkunci->name,
                    $gudang->name,
                    Format::jumlah($sebelum),
                    Format::jumlah(abs($delta)),
                ));
            }

            $stokGudang->forceFill(['quantity' => $sesudah])->save();
            $terkunci->forceFill(['stock' => round((float) $terkunci->stock + $delta, 3)])->save();

            // Sinkronkan instance milik pemanggil agar tidak menampilkan stok basi.
            $produk->setAttribute('stock', $terkunci->stock);
            $produk->syncOriginalAttribute('stock');

            return StockMovement::create([
                'warehouse_id' => $gudang->getKey(),
                'product_id' => $terkunci->getKey(),
                'type' => $jenis,
                'reference_type' => $referensi?->getMorphClass(),
                'reference_id' => $referensi?->getKey(),
                'quantity' => $delta,
                'stock_before' => $sebelum,
                'stock_after' => $sesudah,
                'note' => $catatan,
                'user_id' => $user?->getKey(),
            ]);
        });
    }

    /**
     * Penyesuaian manual. Penyesuaian bisa menambah ('in') atau mengurangi ('out');
     * barang rusak dan hilang selalu mengurangi.
     */
    public function sesuaikan(
        Product $produk,
        StockMovementType $jenis,
        string $arah,
        float|int|string $jumlah,
        string $catatan,
        User $user,
        ?Warehouse $gudang = null,
    ): StockMovement {
        if (! in_array($jenis, StockMovementType::manual(), true)) {
            throw new AturanBisnisException('Jenis mutasi ini tidak dapat dibuat manual.');
        }

        $besar = abs(round((float) $jumlah, 3));
        $tambah = $jenis === StockMovementType::Adjustment && $arah === 'in';

        return $this->catat($produk, $jenis, $tambah ? $besar : -$besar, null, trim($catatan), $user, $gudang);
    }

    public function defaultWarehouse(): Warehouse
    {
        return $this->defaultWarehouse ??= Warehouse::default();
    }

    /** Baris stok produk di gudang, dibuat bila belum ada, lalu dikunci sampai transaksi selesai. */
    private function lockWarehouseStock(Warehouse $gudang, Product $produk): WarehouseStock
    {
        DB::table('warehouse_stocks')->insertOrIgnore([
            'warehouse_id' => $gudang->getKey(),
            'product_id' => $produk->getKey(),
            'quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WarehouseStock::query()
            ->where('warehouse_id', $gudang->getKey())
            ->where('product_id', $produk->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
