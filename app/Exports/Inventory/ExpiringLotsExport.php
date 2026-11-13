<?php

namespace App\Exports\Inventory;

use App\Exports\ListExport;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch yang masih bersisa dan sudah/akan kedaluwarsa dalam N hari (bawaan Pengaturan → Inventori),
 * di gudang cabang aktif. Filter: hari, gudang, cari (nama/SKU/batch).
 */
class ExpiringLotsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Mendekati Kedaluwarsa';
    }

    public function days(): int
    {
        return (int) ($this->filters['hari'] ?? setting('inventory.expiry_warning_days', 30));
    }

    protected function baseQuery(): Builder
    {
        $branchIds = app(CurrentBranch::class)->scopeIds();

        return StockLot::query()->with(['product:id,sku,name,unit_id', 'product.unit:id,name', 'warehouse:id,name'])
            ->available()->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today()->addDays($this->days())->toDateString())
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('warehouse_id', Warehouse::withTrashed()->whereIn('branch_id', $branchIds)->pluck('id')))
            ->when($this->filters['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($this->filters['cari'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $w) => $w->where('batch_number', 'like', "%{$v}%")
                ->orWhereHas('product', fn (Builder $p) => $p->withTrashed()->where('name', 'like', "%{$v}%")->orWhere('sku', 'like', "%{$v}%"))));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('expiry_date')->orderBy('id');
    }

    protected function sorts(): array
    {
        return ['expiry' => 'expiry_date', 'quantity' => 'quantity'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'SKU', 'value' => fn (StockLot $l) => $l->product?->sku],
            ['label' => 'Produk', 'value' => fn (StockLot $l) => $l->product?->name],
            ['label' => 'Gudang', 'value' => fn (StockLot $l) => $l->warehouse?->name],
            ['label' => 'Batch', 'value' => fn (StockLot $l) => $l->batch_number ?? '-'],
            ['label' => 'Kedaluwarsa', 'value' => fn (StockLot $l) => $l->expiry_date, 'type' => 'date'],
            ['label' => 'Sisa hari', 'value' => fn (StockLot $l) => (int) today()->diffInDays($l->expiry_date, false), 'type' => 'number'],
            ['label' => 'Sisa stok', 'value' => fn (StockLot $l) => $l->quantity, 'type' => 'qty'],
            ['label' => 'Satuan', 'value' => fn (StockLot $l) => $l->product?->unit?->name],
            ['label' => 'Nilai', 'value' => fn (StockLot $l) => round((float) $l->quantity * (float) $l->unit_cost, 2), 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter(['Dalam' => $this->days().' hari', 'Pencarian' => $this->filters['cari'] ?? null]);
    }
}
