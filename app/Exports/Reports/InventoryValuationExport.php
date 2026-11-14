<?php

namespace App\Exports\Reports;

use App\Exports\Concerns\AppendsTotalRow;
use App\Exports\ListExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockReportService;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithEvents;

/**
 * Nilai persediaan per produk pada akhir tanggal `per` (bawaan hari ini), di satu gudang atau semua gudang
 * cabang aktif. Kolom tambahan dari StockReportService: qty_at, value_at. Hanya produk yang berstok pada tanggal itu.
 */
class InventoryValuationExport extends ListExport implements WithEvents
{
    use AppendsTotalRow;

    /** @var array{items: int, value: float}|null */
    private ?array $totals = null;

    public function documentTitle(): string
    {
        return 'Nilai Persediaan';
    }

    public function date(): string
    {
        return $this->filters['per'] ?? today()->toDateString();
    }

    protected function baseQuery(): Builder
    {
        $report = app(StockReportService::class);
        $ids = $report->warehouseIds(isset($this->filters['gudang']) ? (int) $this->filters['gudang'] : null);
        $after = $report->movements($ids, StockReportService::dayAfter($this->date()))
            ->selectRaw('SUM(m.quantity) as qty, SUM('.StockReportService::COST.') as value');

        $positions = Product::withTrashed()->select('products.*')
            ->selectRaw('COALESCE(ws.qty, 0) - COALESCE(mv.qty, 0) as qty_at')
            ->selectRaw('COALESCE(ws.value, 0) - COALESCE(mv.value, 0) as value_at')
            ->leftJoinSub($report->currentPosition($ids), 'ws', 'ws.product_id', '=', 'products.id')
            ->leftJoinSub($after, 'mv', 'mv.product_id', '=', 'products.id');

        return Product::withTrashed()->fromSub($positions, 'products')
            ->with(['category:id,name', 'unit:id,name'])
            ->whereRaw('ROUND(qty_at, 3) <> 0')
            ->filter(Arr::only($this->filters, ['cari', 'kategori']));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('name');
    }

    protected function sorts(): array
    {
        return [
            'name' => 'name',
            'sku' => 'sku',
            'category' => fn (Builder $q, string $dir) => $q->orderBy(Category::select('name')->whereColumn('categories.id', 'products.category_id'), $dir),
            'qty' => 'qty_at',
            'value' => 'value_at',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'SKU', 'value' => fn (Product $p) => $p->sku],
            ['label' => 'Produk', 'value' => fn (Product $p) => $p->name],
            ['label' => 'Kategori', 'value' => fn (Product $p) => $p->category?->name],
            ['label' => 'Satuan', 'value' => fn (Product $p) => $p->unit?->name],
            ['label' => 'Jumlah', 'value' => fn (Product $p) => round((float) $p->qty_at, 3), 'type' => 'qty'],
            ['label' => 'Biaya per Unit', 'value' => fn (Product $p) => self::unitCost($p), 'type' => 'money'],
            ['label' => 'Nilai Persediaan', 'value' => fn (Product $p) => self::value($p), 'type' => 'money'],
        ];
    }

    /** Stok minus tidak bernilai (sama dengan halaman Stok). */
    public static function value(Product $product): float
    {
        return (float) $product->qty_at > 0 ? round((float) $product->value_at, 2) : 0.0;
    }

    public static function unitCost(Product $product): ?float
    {
        return (float) $product->qty_at > 0 ? round((float) $product->value_at / (float) $product->qty_at, 2) : null;
    }

    /** @return array{items: int, value: float} jumlah produk & total nilai seluruh baris (bukan hanya satu halaman) */
    public function totals(): array
    {
        if ($this->totals !== null) {
            return $this->totals;
        }
        $row = DB::query()->fromSub($this->baseQuery()->toBase(), 't')
            ->selectRaw('COUNT(*) as items, COALESCE(SUM(CASE WHEN qty_at > 0 THEN value_at ELSE 0 END), 0) as value')
            ->first();

        return $this->totals = ['items' => (int) $row->items, 'value' => round((float) $row->value, 2)];
    }

    public function totalRow(): array
    {
        return ['Nilai Persediaan' => $this->totals()['value']];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Per tanggal' => Format::tanggal($this->date()),
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : 'Semua gudang',
            'Kategori' => isset($f['kategori']) ? Category::find($f['kategori'])?->name : null,
            'Pencarian' => $f['cari'] ?? null,
            'Total nilai' => Format::rupiah($this->totals()['value']),
        ]);
    }
}
