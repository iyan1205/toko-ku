<?php

namespace App\Reports;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Agregasi laporan penjualan untuk satu rentang tanggal.
 *
 * Hanya transaksi berstatus "completed" yang dihitung. Penjualan yang dibatalkan (void)
 * otomatis tersisih, sehingga angka laporan selalu cocok dengan stok, piutang, dan kas.
 *
 * Laba kotor dihitung di tingkat dokumen: grand_total - HPP. Ini memakai harga modal yang
 * DISALIN saat transaksi (sale_items.cost_price), jadi laporan lama tidak berubah saat harga
 * beli produk diperbarui. Diskon transaksi ikut terpotong karena grand_total sudah bersih.
 */
final class LaporanPenjualan
{
    /** @param int|null $kasirId hanya penjualan kasir ini (dashboard kasir); null = semua kasir */
    public function __construct(
        private readonly CarbonImmutable $dari,
        private readonly CarbonImmutable $sampai,
        private readonly ?int $kasirId = null,
    ) {}

    public function dari(): CarbonImmutable
    {
        return $this->dari;
    }

    public function sampai(): CarbonImmutable
    {
        return $this->sampai;
    }

    /** @return array<string, float|int> */
    public function ringkasan(): array
    {
        $dokumen = $this->penjualan()->selectRaw(
            'COUNT(*) as jumlah,'
            .' COALESCE(SUM(grand_total), 0) as omzet,'
            .' COALESCE(SUM(balance), 0) as piutang,'
            .' COALESCE(SUM(item_discount), 0) as diskon_item,'
            .' COALESCE(SUM(discount), 0) as diskon_transaksi'
        )->first();

        $baris = $this->baris()->selectRaw(
            'COALESCE(SUM(sale_items.quantity), 0) as jumlah_item,'
            .' COALESCE(SUM(sale_items.cost_price * sale_items.quantity), 0) as hpp'
        )->first();

        $omzet = (float) $dokumen->omzet;
        $hpp = (float) $baris->hpp;
        $laba = $omzet - $hpp;
        $jumlah = (int) $dokumen->jumlah;

        return [
            'jumlah_transaksi' => $jumlah,
            'jumlah_item' => (float) $baris->jumlah_item,
            'omzet' => $omzet,
            'hpp' => $hpp,
            'laba' => $laba,
            'margin' => $omzet > 0 ? round($laba / $omzet * 100, 1) : 0.0,
            'rata_rata' => $jumlah > 0 ? $omzet / $jumlah : 0.0,
            'piutang' => (float) $dokumen->piutang,
            'diskon_item' => (float) $dokumen->diskon_item,
            'diskon_transaksi' => (float) $dokumen->diskon_transaksi,
        ];
    }

    /**
     * Rekap per hari. Hari tanpa transaksi tidak ditampilkan (bukan nol semua) supaya
     * tabel tetap ringkas untuk rentang panjang.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function harian(): Collection
    {
        $omzet = $this->penjualan()
            ->selectRaw('DATE(sold_at) as tanggal, COUNT(*) as jumlah, COALESCE(SUM(grand_total), 0) as omzet')
            ->groupByRaw('DATE(sold_at)')
            ->orderBy('tanggal')
            ->get()
            ->keyBy('tanggal');

        $hpp = $this->baris()
            ->selectRaw('DATE(sales.sold_at) as tanggal, COALESCE(SUM(sale_items.cost_price * sale_items.quantity), 0) as hpp')
            ->groupByRaw('DATE(sales.sold_at)')
            ->get()
            ->pluck('hpp', 'tanggal');

        return $omzet->map(function ($baris, $tanggal) use ($hpp) {
            $nilaiOmzet = (float) $baris->omzet;
            $nilaiHpp = (float) ($hpp[$tanggal] ?? 0);

            return [
                'tanggal' => (string) $tanggal,
                'jumlah' => (int) $baris->jumlah,
                'omzet' => $nilaiOmzet,
                'hpp' => $nilaiHpp,
                'laba' => $nilaiOmzet - $nilaiHpp,
            ];
        })->values();
    }

    /**
     * Rekap per metode pembayaran, diurutkan dari omzet terbesar.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function perMetode(): Collection
    {
        return $this->penjualan()
            ->selectRaw('payment_method, COUNT(*) as jumlah, COALESCE(SUM(grand_total), 0) as omzet, COALESCE(SUM(balance), 0) as piutang')
            ->groupBy('payment_method')
            ->orderByDesc('omzet')
            ->get()
            ->map(fn ($baris) => [
                // getRawOriginal: kolom ini di-cast ke enum oleh model Sale, jadi baca nilai mentahnya.
                'metode' => PaymentMethod::tryFrom((string) $baris->getRawOriginal('payment_method')),
                'jumlah' => (int) $baris->jumlah,
                'omzet' => (float) $baris->omzet,
                'piutang' => (float) $baris->piutang,
            ]);
    }

    /**
     * Rekap per kasir. Nama diambil dari pengguna (termasuk yang sudah dihapus, karena
     * penjualan lama tetap harus bisa dipertanggungjawabkan).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function perKasir(): Collection
    {
        $dokumen = $this->penjualan()
            ->selectRaw('user_id, COUNT(*) as jumlah, COALESCE(SUM(grand_total), 0) as omzet')
            ->groupBy('user_id')
            ->orderByDesc('omzet')
            ->get();

        $hpp = $this->baris()
            ->selectRaw('sales.user_id as user_id, COALESCE(SUM(sale_items.cost_price * sale_items.quantity), 0) as hpp')
            ->groupBy('sales.user_id')
            ->get()
            ->pluck('hpp', 'user_id');

        $nama = User::withTrashed()
            ->whereIn('id', $dokumen->pluck('user_id'))
            ->pluck('name', 'id');

        return $dokumen->map(function ($baris) use ($hpp, $nama) {
            $nilaiOmzet = (float) $baris->omzet;
            $nilaiHpp = (float) ($hpp[$baris->user_id] ?? 0);

            return [
                'nama' => (string) ($nama[$baris->user_id] ?? 'Pengguna dihapus'),
                'jumlah' => (int) $baris->jumlah,
                'omzet' => $nilaiOmzet,
                'laba' => $nilaiOmzet - $nilaiHpp,
            ];
        });
    }

    /**
     * Produk terlaris berdasarkan omzet. Nama dan satuan diambil dari baris transaksi
     * (salinan saat penjualan), bukan dari master produk yang bisa berubah.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function produkTeratas(int $batas = 10): Collection
    {
        return $this->baris()
            ->selectRaw(
                'sale_items.product_name, sale_items.sku, sale_items.unit_name,'
                .' COALESCE(SUM(sale_items.quantity), 0) as jumlah,'
                .' COALESCE(SUM(sale_items.line_total), 0) as omzet,'
                .' COALESCE(SUM(sale_items.cost_price * sale_items.quantity), 0) as hpp'
            )
            ->groupBy('sale_items.product_name', 'sale_items.sku', 'sale_items.unit_name')
            ->orderByDesc('omzet')
            ->limit($batas)
            ->get()
            ->map(fn ($baris) => [
                'nama' => (string) $baris->product_name,
                'sku' => (string) $baris->sku,
                'satuan' => (string) $baris->unit_name,
                'jumlah' => (float) $baris->jumlah,
                'omzet' => (float) $baris->omzet,
                'laba' => (float) $baris->omzet - (float) $baris->hpp,
            ]);
    }

    /** Dokumen penjualan yang selesai pada rentang tanggal. */
    private function penjualan(): Builder
    {
        return Sale::query()
            ->where('status', PurchaseStatus::Completed->value)
            ->whereBetween('sold_at', [$this->dari, $this->sampai])
            ->when($this->kasirId, fn (Builder $q, int $id) => $q->where('user_id', $id));
    }

    /** Baris penjualan (join ke dokumennya) yang selesai pada rentang tanggal. */
    private function baris(): Builder
    {
        $cabang = app(CurrentBranch::class)->scopeIds();

        // Join tidak melewati BranchScope milik Sale: saring cabangnya di sini.
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->when($cabang !== null, fn (Builder $q) => $q->whereIn('sales.branch_id', $cabang))
            ->where('sales.status', PurchaseStatus::Completed->value)
            ->whereBetween('sales.sold_at', [$this->dari, $this->sampai])
            ->when($this->kasirId, fn (Builder $q, int $id) => $q->where('sales.user_id', $id));
    }
}
