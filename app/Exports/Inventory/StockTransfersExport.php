<?php

namespace App\Exports\Inventory;

use App\Enums\PurchaseStatus;
use App\Exports\ListExport;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

class StockTransfersExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Transfer Stok';
    }

    protected function baseQuery(): Builder
    {
        return StockTransfer::query()
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name'])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('transfer_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'transfer_date',
            'quantity' => 'total_quantity',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (StockTransfer $t) => $t->number],
            ['label' => 'Tanggal', 'value' => fn (StockTransfer $t) => $t->transfer_date, 'type' => 'date'],
            ['label' => 'Dari Gudang', 'value' => fn (StockTransfer $t) => $t->fromWarehouse?->name],
            ['label' => 'Ke Gudang', 'value' => fn (StockTransfer $t) => $t->toWarehouse?->name],
            ['label' => 'Jumlah Produk', 'value' => fn (StockTransfer $t) => $t->items_count, 'type' => 'qty'],
            ['label' => 'Total Kuantitas', 'value' => fn (StockTransfer $t) => $t->total_quantity, 'type' => 'qty'],
            ['label' => 'Status', 'value' => fn (StockTransfer $t) => $t->status->label()],
            ['label' => 'Catatan', 'value' => fn (StockTransfer $t) => $t->note],
            ['label' => 'Dibuat Oleh', 'value' => fn (StockTransfer $t) => $t->user?->name],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : null,
            'Status' => isset($f['status']) ? PurchaseStatus::tryFrom($f['status'])?->label() : null,
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
