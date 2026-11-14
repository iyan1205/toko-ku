<?php

namespace App\Services\Inventory;

use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bahan query Laporan → Stok (nilai persediaan, perputaran, barang lambat/mati, kartu stok per batch).
 *
 * Posisi stok dan nilai pada akhir suatu tanggal = posisi gudang sekarang (warehouse_stocks) dikurangi mutasi
 * sesudah tanggal itu. Dengan begitu posisi hari ini selalu sama dengan halaman Stok, dan stok awal yang tidak
 * punya mutasi (sebelum Fase 16) tetap terhitung. Nilai memakai biaya mutasi (total_cost), bukan harga beli.
 *
 * Mutasi sebelum Fase 16 tidak punya biaya; nilainya diperkirakan dengan harga beli produk, sama seperti stok awal
 * dinilai saat Fase 16 dipasang.
 *
 * Pemakaian = penjualan (dikurangi retur penjualan) + bahan yang terpakai produksi. Inilah HPP/pemakaian untuk
 * perputaran stok dan dasar penilaian barang lambat/mati. Transfer, penyesuaian, rusak, dan hilang tidak dihitung.
 *
 * Query mutasi memakai alias `m` (stock_movements) dan `mp` (produknya).
 */
final class StockReportService
{
    /** Biaya mutasi bertanda; mutasi lama tanpa biaya = jumlah × harga beli. */
    public const COST = 'COALESCE(m.total_cost, m.quantity * mp.purchase_price)';

    /** Kondisi SQL mutasi pemakaian. */
    public const USAGE = "(m.type IN ('sale', 'sale_return') OR (m.type = 'production' AND m.quantity < 0))";

    /**
     * Gudang dalam cakupan laporan: gudang terpilih bila ada di cabang aktif, atau semua gudang cabang aktif
     * (termasuk yang nonaktif/dihapus, karena stok dan riwayatnya tetap ada).
     *
     * @return list<int>
     */
    public function warehouseIds(?int $warehouseId = null): array
    {
        return Warehouse::withTrashed()->inCurrentBranch()
            ->when($warehouseId, fn ($q, $id) => $q->whereKey($id))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Awal hari (batas bawah created_at). */
    public static function startOf(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfDay()->format('Y-m-d H:i:s');
    }

    /** Awal hari berikutnya: mutasi "sampai tanggal X" = created_at < nilai ini. */
    public static function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->startOfDay()->format('Y-m-d H:i:s');
    }

    /**
     * Stok dan nilai sekarang per produk di gudang terpilih: product_id, qty, value.
     *
     * @param  list<int>  $warehouseIds
     */
    public function currentPosition(array $warehouseIds): Builder
    {
        return DB::table('warehouse_stocks')->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw('SUM(quantity) as qty, SUM(value) as value');
    }

    /**
     * Mutasi per produk di gudang terpilih, mulai dari $since (null = semua). Kolom ditambah pemanggil.
     *
     * @param  list<int>  $warehouseIds
     */
    public function movements(array $warehouseIds, ?string $since = null): Builder
    {
        return DB::table('stock_movements as m')->join('products as mp', 'mp.id', '=', 'm.product_id')
            ->whereIn('m.warehouse_id', $warehouseIds)
            ->when($since, fn (Builder $q, string $from) => $q->where('m.created_at', '>=', $from))
            ->groupBy('m.product_id')
            ->select('m.product_id');
    }
}
