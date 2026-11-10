<?php

namespace App\Exports\Inventory;

use App\Enums\StockOpnameStatus;
use App\Exports\ListExport;
use App\Models\StockOpname;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

class StockOpnamesExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Stok Opname';
    }

    protected function baseQuery(): Builder
    {
        return StockOpname::query()
            ->with(['warehouse:id,name', 'user:id,name', 'finalizedBy:id,name'])
            ->withCount(['items', 'items as selisih_count' => fn (Builder $q) => $q->where('difference', '!=', 0)])
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('opname_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'opname_date',
            'difference' => 'selisih_count',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (StockOpname $o) => $o->number],
            ['label' => 'Tanggal', 'value' => fn (StockOpname $o) => $o->opname_date, 'type' => 'date'],
            ['label' => 'Gudang', 'value' => fn (StockOpname $o) => $o->warehouse?->name],
            ['label' => 'Produk Dihitung', 'value' => fn (StockOpname $o) => $o->items_count, 'type' => 'qty'],
            ['label' => 'Produk Berselisih', 'value' => fn (StockOpname $o) => $o->selisih_count, 'type' => 'qty'],
            ['label' => 'Status', 'value' => fn (StockOpname $o) => $o->status->label()],
            ['label' => 'Dihitung Oleh', 'value' => fn (StockOpname $o) => $o->user?->name],
            ['label' => 'Diselesaikan Oleh', 'value' => fn (StockOpname $o) => $o->finalizedBy?->name],
            ['label' => 'Catatan', 'value' => fn (StockOpname $o) => $o->note],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : null,
            'Status' => isset($f['status']) ? StockOpnameStatus::tryFrom($f['status'])?->label() : null,
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
