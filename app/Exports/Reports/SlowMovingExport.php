<?php

namespace App\Exports\Reports;

use App\Exports\ListExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Barang yang masih berstok dinilai dari pemakaiannya (penjualan bersih + bahan produksi) dalam N hari terakhir:
 *   mati   = tidak keluar sama sekali dalam N hari,
 *   lambat = masih keluar, tetapi stok sekarang lebih banyak dari yang keluar selama N hari (habis lebih dari N hari lagi),
 *   baru   = pertama kali masuk dalam N hari dan belum keluar (tidak dianggap mati),
 *   normal = sisanya.
 * Bawaan menampilkan mati dan lambat, nilai persediaan terbesar dulu (uang yang tertahan).
 */
class SlowMovingExport extends ListExport
{
    public const STATUSES = ['mati' => 'Mati', 'lambat' => 'Lambat', 'baru' => 'Baru masuk', 'normal' => 'Normal'];

    public const DAYS = [30, 60, 90, 180, 365];

    public function documentTitle(): string
    {
        return 'Barang Lambat & Mati';
    }

    public function days(): int
    {
        return (int) ($this->filters['hari'] ?? 90);
    }

    /** Status yang ditampilkan: filter `status` (semua = semua status), bawaan mati + lambat. @return list<string> */
    public function statuses(): array
    {
        return match ($this->filters['status'] ?? null) {
            'semua' => array_keys(self::STATUSES),
            'mati', 'lambat', 'baru', 'normal' => [$this->filters['status']],
            default => ['mati', 'lambat'],
        };
    }

    /** Produk berstok di gudang terpilih beserta pemakaian dan statusnya, belum disaring status. */
    public function positions(): Builder
    {
        $report = app(StockReportService::class);
        $ids = $report->warehouseIds(isset($this->filters['gudang']) ? (int) $this->filters['gudang'] : null);
        $since = StockReportService::startOf(CarbonImmutable::today()->subDays($this->days())->toDateString());
        $usage = StockReportService::USAGE;

        $movements = $report->movements($ids)
            ->selectRaw("SUM(CASE WHEN m.created_at >= ? AND {$usage} THEN -m.quantity ELSE 0 END) as usage_qty", [$since])
            ->selectRaw("MAX(CASE WHEN m.quantity < 0 AND {$usage} THEN m.created_at END) as last_out_at")
            ->selectRaw("MAX(CASE WHEN m.quantity > 0 AND m.type <> 'sale_return' THEN m.created_at END) as last_in_at")
            ->selectRaw('MIN(CASE WHEN m.quantity > 0 THEN m.created_at END) as first_in_at');

        $positions = Product::query()->stockable()->select('products.*')
            ->selectRaw('COALESCE(ws.qty, 0) as stock_qty, COALESCE(ws.value, 0) as stock_value')
            ->selectRaw('COALESCE(mv.usage_qty, 0) as usage_qty, mv.last_out_at, mv.last_in_at')
            ->selectRaw("CASE WHEN COALESCE(mv.usage_qty, 0) <= 0 THEN (CASE WHEN mv.first_in_at >= ? THEN 'baru' ELSE 'mati' END)
                WHEN COALESCE(ws.qty, 0) > mv.usage_qty THEN 'lambat' ELSE 'normal' END as movement_status", [$since])
            ->leftJoinSub($report->currentPosition($ids), 'ws', 'ws.product_id', '=', 'products.id')
            ->leftJoinSub($movements, 'mv', 'mv.product_id', '=', 'products.id');

        return Product::query()->fromSub($positions, 'products')
            ->with(['category:id,name', 'unit:id,name'])
            ->whereRaw('ROUND(stock_qty, 3) > 0')
            ->filter(Arr::only($this->filters, ['cari', 'kategori']));
    }

    protected function baseQuery(): Builder
    {
        return $this->positions()->whereIn('movement_status', $this->statuses());
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('stock_value')->orderBy('name');
    }

    protected function sorts(): array
    {
        return [
            'name' => 'name',
            'stock' => 'stock_qty',
            'value' => 'stock_value',
            'usage' => 'usage_qty',
            'last_out' => 'last_out_at',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'SKU', 'value' => fn (Product $p) => $p->sku],
            ['label' => 'Produk', 'value' => fn (Product $p) => $p->name],
            ['label' => 'Kategori', 'value' => fn (Product $p) => $p->category?->name],
            ['label' => 'Stok', 'value' => fn (Product $p) => round((float) $p->stock_qty, 3), 'type' => 'qty'],
            ['label' => 'Satuan', 'value' => fn (Product $p) => $p->unit?->name],
            ['label' => 'Nilai Persediaan', 'value' => fn (Product $p) => round((float) $p->stock_value, 2), 'type' => 'money'],
            ['label' => 'Keluar '.$this->days().' Hari', 'value' => fn (Product $p) => round((float) $p->usage_qty, 3), 'type' => 'qty'],
            ['label' => 'Habis Dalam (hari)', 'value' => fn (Product $p) => $this->daysOfCover($p), 'type' => 'qty'],
            ['label' => 'Terakhir Keluar', 'value' => fn (Product $p) => self::date($p->last_out_at), 'type' => 'date'],
            ['label' => 'Terakhir Masuk', 'value' => fn (Product $p) => self::date($p->last_in_at), 'type' => 'date'],
            ['label' => 'Status', 'value' => fn (Product $p) => self::STATUSES[$p->movement_status] ?? $p->movement_status],
        ];
    }

    /** Stok sekarang habis dalam berapa hari dengan laju keluar N hari terakhir (null = tidak keluar). */
    public function daysOfCover(Product $product): ?float
    {
        return (float) $product->usage_qty > 0 ? round((float) $product->stock_qty * $this->days() / (float) $product->usage_qty, 1) : null;
    }

    public static function date(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }

    /** Jumlah produk dan nilai per status (tanpa saringan status). @return Collection<string, object{items: int, value: float}> */
    public function summary(): Collection
    {
        return DB::query()->fromSub($this->positions()->toBase(), 't')
            ->groupBy('movement_status')
            ->selectRaw('movement_status, COUNT(*) as items, COALESCE(SUM(stock_value), 0) as value')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->movement_status => (object) ['items' => (int) $row->items, 'value' => round((float) $row->value, 2)]]);
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Periode' => $this->days().' hari terakhir',
            'Status' => implode(', ', array_map(fn ($s) => self::STATUSES[$s], $this->statuses())),
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : 'Semua gudang',
            'Kategori' => isset($f['kategori']) ? Category::find($f['kategori'])?->name : null,
            'Pencarian' => $f['cari'] ?? null,
        ]);
    }
}
