<?php

namespace App\Exports\Inventory;

use App\Enums\StockMovementType;
use App\Exports\ListExport;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\DocumentLink;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kartu stok: setiap mutasi, terbaru dulu. Saldo = stok di gudang mutasi tersebut
 * setelah mutasi terjadi.
 */
class StockMovementsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Kartu Stok';
    }

    protected function baseQuery(): Builder
    {
        return StockMovement::query()
            ->with(['product:id,sku,name,unit_id', 'product.unit:id,name', 'warehouse:id,code,name', 'user:id,name', 'reference'])
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return ['date' => 'id'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Waktu', 'value' => fn (StockMovement $m) => $m->created_at, 'type' => 'datetime'],
            ['label' => 'Gudang', 'value' => fn (StockMovement $m) => $m->warehouse?->name],
            ['label' => 'SKU', 'value' => fn (StockMovement $m) => $m->product?->sku],
            ['label' => 'Produk', 'value' => fn (StockMovement $m) => $m->product?->name],
            ['label' => 'Jenis', 'value' => fn (StockMovement $m) => $m->type->label()],
            ['label' => 'Dokumen', 'value' => fn (StockMovement $m) => DocumentLink::number($m->reference)],
            ['label' => 'Masuk', 'value' => fn (StockMovement $m) => (float) $m->quantity > 0 ? $m->quantity : null, 'type' => 'qty'],
            ['label' => 'Keluar', 'value' => fn (StockMovement $m) => (float) $m->quantity < 0 ? abs((float) $m->quantity) : null, 'type' => 'qty'],
            ['label' => 'Saldo Gudang', 'value' => fn (StockMovement $m) => $m->stock_after, 'type' => 'qty'],
            ['label' => 'Catatan', 'value' => fn (StockMovement $m) => $m->note],
            ['label' => 'Oleh', 'value' => fn (StockMovement $m) => $m->user?->name ?? 'Sistem'],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Produk' => isset($f['produk']) ? Product::withTrashed()->find($f['produk'])?->name : null,
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : null,
            'Jenis' => isset($f['jenis']) ? StockMovementType::tryFrom($f['jenis'])?->label() : null,
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
