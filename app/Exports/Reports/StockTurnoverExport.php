<?php

namespace App\Exports\Reports;

use App\Exports\Concerns\AppendsTotalRow;
use App\Exports\ListExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockReportService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithEvents;

/**
 * Perputaran stok per produk untuk periode dari–sampai:
 *   persediaan awal = nilai pada akhir hari sebelum `dari`, persediaan akhir = nilai pada akhir `sampai`,
 *   HPP/pemakaian = biaya penjualan (dikurangi retur) + bahan terpakai produksi dalam periode,
 *   perputaran = HPP ÷ rata-rata persediaan ((awal + akhir) ÷ 2), hari persediaan = hari periode ÷ perputaran.
 */
class StockTurnoverExport extends ListExport implements WithEvents
{
    use AppendsTotalRow;

    /** Urutan berdasarkan perputaran: HPP ÷ rata-rata persediaan (kosong bila rata-rata 0). */
    private const TURNOVER_SQL = 'usage_value / NULLIF((opening_value + closing_value) / 2, 0)';

    /** @var array<string, float>|null */
    private ?array $totals = null;

    public function documentTitle(): string
    {
        return 'Perputaran Stok';
    }

    public function from(): string
    {
        return $this->filters['dari'] ?? today()->subMonthsNoOverflow(2)->startOfMonth()->toDateString();
    }

    public function to(): string
    {
        return $this->filters['sampai'] ?? today()->toDateString();
    }

    /** Jumlah hari periode (termasuk tanggal awal dan akhir). */
    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from())->diffInDays(CarbonImmutable::parse($this->to())) + 1;
    }

    protected function baseQuery(): Builder
    {
        $report = app(StockReportService::class);
        $ids = $report->warehouseIds(isset($this->filters['gudang']) ? (int) $this->filters['gudang'] : null);
        $end = StockReportService::dayAfter($this->to());
        $usage = StockReportService::USAGE;
        $cost = StockReportService::COST;

        $movements = $report->movements($ids, StockReportService::startOf($this->from()))
            ->selectRaw("SUM(m.quantity) as qty_since, SUM({$cost}) as value_since")
            ->selectRaw('SUM(CASE WHEN m.created_at >= ? THEN m.quantity ELSE 0 END) as qty_after', [$end])
            ->selectRaw("SUM(CASE WHEN m.created_at >= ? THEN {$cost} ELSE 0 END) as value_after", [$end])
            ->selectRaw("SUM(CASE WHEN m.created_at < ? AND {$usage} THEN -m.quantity ELSE 0 END) as usage_qty", [$end])
            ->selectRaw("SUM(CASE WHEN m.created_at < ? AND {$usage} THEN -{$cost} ELSE 0 END) as usage_value", [$end]);

        $positions = Product::withTrashed()->select('products.*')
            ->selectRaw('COALESCE(ws.qty, 0) - COALESCE(mv.qty_since, 0) as opening_qty')
            ->selectRaw('COALESCE(ws.value, 0) - COALESCE(mv.value_since, 0) as opening_value')
            ->selectRaw('COALESCE(ws.qty, 0) - COALESCE(mv.qty_after, 0) as closing_qty')
            ->selectRaw('COALESCE(ws.value, 0) - COALESCE(mv.value_after, 0) as closing_value')
            ->selectRaw('COALESCE(mv.usage_qty, 0) as usage_qty')
            ->selectRaw('COALESCE(mv.usage_value, 0) as usage_value')
            ->leftJoinSub($report->currentPosition($ids), 'ws', 'ws.product_id', '=', 'products.id')
            ->leftJoinSub($movements, 'mv', 'mv.product_id', '=', 'products.id');

        return Product::withTrashed()->fromSub($positions, 'products')
            ->with(['category:id,name', 'unit:id,name'])
            ->where(fn (Builder $q) => $q->whereRaw('ROUND(opening_qty, 3) <> 0')->orWhereRaw('ROUND(closing_qty, 3) <> 0')->orWhereRaw('ROUND(usage_qty, 3) <> 0'))
            ->filter(Arr::only($this->filters, ['cari', 'kategori']));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('usage_value')->orderBy('name');
    }

    protected function sorts(): array
    {
        return [
            'name' => 'name',
            'usage' => 'usage_value',
            'closing' => 'closing_value',
            'turnover' => fn (Builder $q, string $dir) => $q->orderByRaw(self::TURNOVER_SQL.' '.$dir),
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'SKU', 'value' => fn (Product $p) => $p->sku],
            ['label' => 'Produk', 'value' => fn (Product $p) => $p->name],
            ['label' => 'Kategori', 'value' => fn (Product $p) => $p->category?->name],
            ['label' => 'Satuan', 'value' => fn (Product $p) => $p->unit?->name],
            ['label' => 'Persediaan Awal', 'value' => fn (Product $p) => self::money($p->opening_qty, $p->opening_value), 'type' => 'money'],
            ['label' => 'Persediaan Akhir', 'value' => fn (Product $p) => self::money($p->closing_qty, $p->closing_value), 'type' => 'money'],
            ['label' => 'Rata-rata Persediaan', 'value' => fn (Product $p) => $this->average($p), 'type' => 'money'],
            ['label' => 'Keluar', 'value' => fn (Product $p) => round((float) $p->usage_qty, 3), 'type' => 'qty'],
            ['label' => 'HPP / Pemakaian', 'value' => fn (Product $p) => round((float) $p->usage_value, 2), 'type' => 'money'],
            ['label' => 'Perputaran (kali)', 'value' => fn (Product $p) => self::turnover((float) $p->usage_value, $this->average($p)), 'type' => 'qty'],
            ['label' => 'Hari Persediaan', 'value' => fn (Product $p) => $this->daysOfInventory((float) $p->usage_value, $this->average($p)), 'type' => 'qty'],
        ];
    }

    /** Stok minus tidak bernilai. */
    private static function money(mixed $qty, mixed $value): float
    {
        return (float) $qty > 0 ? round((float) $value, 2) : 0.0;
    }

    public function average(Product $product): float
    {
        return round((self::money($product->opening_qty, $product->opening_value) + self::money($product->closing_qty, $product->closing_value)) / 2, 2);
    }

    public static function turnover(float $usage, float $average): ?float
    {
        return $average > 0 ? round($usage / $average, 2) : null;
    }

    /** Berapa hari rata-rata persediaan cukup dengan laju pemakaian periode ini. */
    public function daysOfInventory(float $usage, float $average): ?float
    {
        return $usage > 0 && $average > 0 ? round($average / $usage * $this->days(), 1) : null;
    }

    /** @return array{opening: float, closing: float, average: float, usage: float, items: int} seluruh baris */
    public function totals(): array
    {
        if ($this->totals !== null) {
            return $this->totals;
        }
        $row = DB::query()->fromSub($this->baseQuery()->toBase(), 't')->selectRaw(
            'COUNT(*) as items, COALESCE(SUM(CASE WHEN opening_qty > 0 THEN opening_value ELSE 0 END), 0) as opening, '
            .'COALESCE(SUM(CASE WHEN closing_qty > 0 THEN closing_value ELSE 0 END), 0) as closing, COALESCE(SUM(usage_value), 0) as usage_total'
        )->first();
        $opening = round((float) $row->opening, 2);
        $closing = round((float) $row->closing, 2);

        return $this->totals = [
            'items' => (int) $row->items, 'opening' => $opening, 'closing' => $closing,
            'average' => round(($opening + $closing) / 2, 2), 'usage' => round((float) $row->usage_total, 2),
        ];
    }

    public function totalRow(): array
    {
        $t = $this->totals();

        return [
            'Persediaan Awal' => $t['opening'], 'Persediaan Akhir' => $t['closing'], 'Rata-rata Persediaan' => $t['average'],
            'HPP / Pemakaian' => $t['usage'], 'Perputaran (kali)' => self::turnover($t['usage'], $t['average']),
            'Hari Persediaan' => $this->daysOfInventory($t['usage'], $t['average']),
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;
        $t = $this->totals();

        return array_filter([
            'Periode' => Format::tanggal($this->from()).' s.d. '.Format::tanggal($this->to()),
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : 'Semua gudang',
            'Kategori' => isset($f['kategori']) ? Category::find($f['kategori'])?->name : null,
            'Pencarian' => $f['cari'] ?? null,
            'Total HPP' => Format::rupiah($t['usage']),
            'Perputaran' => ($x = self::turnover($t['usage'], $t['average'])) !== null ? Format::jumlah($x).' kali' : null,
        ]);
    }
}
