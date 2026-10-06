<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA pintu untuk mengubah products.stock.
 * Setiap perubahan: baris produk dikunci, stok dihitung ulang dari nilai terkini,
 * lalu stok dan stock_movements ditulis dalam satu transaksi.
 */
class StockService
{
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
    ): StockMovement {
        $delta = round((float) $jumlah, 3);

        if ($delta == 0.0) {
            throw new AturanBisnisException('Jumlah perubahan stok tidak boleh nol.');
        }

        return DB::transaction(function () use ($produk, $jenis, $delta, $referensi, $catatan, $user) {
            // Kunci baris produk: dua kasir yang menjual barang yang sama tidak boleh saling menimpa.
            $terkunci = Product::withTrashed()->whereKey($produk->getKey())->lockForUpdate()->firstOrFail();

            $sebelum = round((float) $terkunci->stock, 3);
            $sesudah = round($sebelum + $delta, 3);

            if ($sesudah < 0 && ! config('pos.allow_negative_stock')) {
                throw new AturanBisnisException(sprintf(
                    'Stok tidak mencukupi. %s: tersedia %s, diminta %s.',
                    $terkunci->name,
                    Format::jumlah($sebelum),
                    Format::jumlah(abs($delta)),
                ));
            }

            $terkunci->forceFill(['stock' => $sesudah])->save();

            // Sinkronkan instance milik pemanggil agar tidak menampilkan stok basi.
            $produk->setAttribute('stock', $terkunci->stock);
            $produk->syncOriginalAttribute('stock');

            return StockMovement::create([
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
    ): StockMovement {
        if (! in_array($jenis, StockMovementType::manual(), true)) {
            throw new AturanBisnisException('Jenis mutasi ini tidak dapat dibuat manual.');
        }

        $besar = abs(round((float) $jumlah, 3));
        $tambah = $jenis === StockMovementType::Adjustment && $arah === 'in';

        return $this->catat($produk, $jenis, $tambah ? $besar : -$besar, null, trim($catatan), $user);
    }
}
