<?php

namespace App\Exports\Reports;

use App\Exports\ListExport;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovementLot;
use App\Models\Warehouse;
use App\Services\Inventory\StockReportService;
use App\Support\DocumentLink;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kartu stok per batch (produk "lacak batch"): setiap bagian mutasi pada satu lot, urut per batch lalu waktu.
 * Saldo batch = isi awal lot tanpa mutasi (stok sebelum Fase 16) + jumlah semua bagian mutasi lot itu sampai baris ini,
 * jadi tetap benar walaupun disaring tanggal.
 */
class BatchStockCardExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Kartu Stok per Batch';
    }

    protected function baseQuery(): Builder
    {
        $ids = app(StockReportService::class)->warehouseIds(isset($this->filters['gudang']) ? (int) $this->filters['gudang'] : null);
        $f = $this->filters;

        return StockMovementLot::query()
            ->select('stock_movement_lots.*')
            ->selectRaw('(CASE WHEN stock_lots.source_movement_id IS NULL THEN stock_lots.quantity_in ELSE 0 END)
                + (SELECT SUM(x.quantity) FROM stock_movement_lots x WHERE x.stock_lot_id = stock_movement_lots.stock_lot_id AND x.id <= stock_movement_lots.id) as lot_balance')
            ->join('stock_lots', 'stock_lots.id', '=', 'stock_movement_lots.stock_lot_id')
            ->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lots.stock_movement_id')
            ->join('products', 'products.id', '=', 'stock_lots.product_id')
            ->where('products.track_batches', true)
            ->whereIn('stock_lots.warehouse_id', $ids)
            ->when($f['produk'] ?? null, fn (Builder $q, $v) => $q->where('stock_lots.product_id', $v))
            ->when($f['lot'] ?? null, fn (Builder $q, $v) => $q->where('stock_lots.id', $v))
            ->when($f['cari'] ?? null, fn (Builder $q, $v) => $q->where('stock_lots.batch_number', 'like', "%{$v}%"))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->where('stock_movements.created_at', '>=', StockReportService::startOf($v)))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->where('stock_movements.created_at', '<', StockReportService::dayAfter($v)))
            ->with([
                'lot:id,product_id,warehouse_id,batch_number,expiry_date', 'lot.warehouse:id,name',
                'lot.product' => fn ($q) => $q->withTrashed()->select('id', 'sku', 'name', 'unit_id'), 'lot.product.unit:id,name',
                'movement' => fn ($q) => $q->with(['reference', 'user:id,name']),
            ]);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('products.name')->orderBy('stock_lots.expiry_date')->orderBy('stock_movement_lots.stock_lot_id')->orderBy('stock_movement_lots.id');
    }

    protected function sorts(): array
    {
        return ['date' => 'stock_movement_lots.id'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Waktu', 'value' => fn (StockMovementLot $r) => $r->movement?->created_at, 'type' => 'datetime'],
            ['label' => 'SKU', 'value' => fn (StockMovementLot $r) => $r->lot?->product?->sku],
            ['label' => 'Produk', 'value' => fn (StockMovementLot $r) => $r->lot?->product?->name],
            ['label' => 'Gudang', 'value' => fn (StockMovementLot $r) => $r->lot?->warehouse?->name],
            ['label' => 'Batch', 'value' => fn (StockMovementLot $r) => $r->lot?->batch_number ?? '-'],
            ['label' => 'Kedaluwarsa', 'value' => fn (StockMovementLot $r) => $r->lot?->expiry_date, 'type' => 'date'],
            ['label' => 'Jenis', 'value' => fn (StockMovementLot $r) => $r->movement?->type->label()],
            ['label' => 'Dokumen', 'value' => fn (StockMovementLot $r) => DocumentLink::number($r->movement?->reference)],
            ['label' => 'Masuk', 'value' => fn (StockMovementLot $r) => (float) $r->quantity > 0 ? $r->quantity : null, 'type' => 'qty'],
            ['label' => 'Keluar', 'value' => fn (StockMovementLot $r) => (float) $r->quantity < 0 ? abs((float) $r->quantity) : null, 'type' => 'qty'],
            ['label' => 'Saldo Batch', 'value' => fn (StockMovementLot $r) => round((float) $r->lot_balance, 3), 'type' => 'qty'],
            ['label' => 'Biaya per Unit', 'value' => fn (StockMovementLot $r) => $r->unit_cost, 'type' => 'money'],
            ['label' => 'Oleh', 'value' => fn (StockMovementLot $r) => $r->movement?->user?->name ?? 'Sistem'],
        ];
    }

    /** Lot produk terpilih di gudang cakupan, kedaluwarsa terdekat dulu (untuk ringkasan di atas tabel). */
    public function lots(): Collection
    {
        if (! isset($this->filters['produk'])) {
            return collect();
        }

        return StockLot::query()->with('warehouse:id,name')
            ->where('product_id', $this->filters['produk'])
            ->whereIn('warehouse_id', app(StockReportService::class)->warehouseIds(isset($this->filters['gudang']) ? (int) $this->filters['gudang'] : null))
            ->orderByRaw('CASE WHEN quantity > 0 THEN 0 ELSE 1 END')->orderBy('expiry_date')->orderBy('id')
            ->get();
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Produk' => isset($f['produk']) ? Product::withTrashed()->find($f['produk'])?->name : null,
            'Batch' => isset($f['lot']) ? (StockLot::find($f['lot'])?->batch_number ?? '-') : ($f['cari'] ?? null),
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : null,
            'Periode' => isset($f['dari']) || isset($f['sampai'])
                ? (isset($f['dari']) ? Format::tanggal($f['dari']) : '…').' s.d. '.(isset($f['sampai']) ? Format::tanggal($f['sampai']) : '…')
                : null,
        ]);
    }
}
