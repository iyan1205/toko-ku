<?php

namespace App\Exports\Inventory;

use App\Exports\ListExport;
use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

/** Daftar gudang beserta jumlah produk dan total stoknya. */
class WarehousesExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Daftar Gudang';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;

        return Warehouse::query()
            ->with('branch:id,name')
            ->withSum('stocks as total_stock', 'quantity')
            ->withCount(['stocks as product_count' => fn (Builder $q) => $q->where('quantity', '!=', 0)])
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$kata}%")->orWhere('code', 'like', "%{$kata}%")->orWhere('address', 'like', "%{$kata}%")))
            ->when($f['cabang'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('is_active', $v === 'aktif'));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->ordered();
    }

    protected function sorts(): array
    {
        return [
            'code' => 'code',
            'name' => 'name',
            'stock' => 'total_stock',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Kode', 'value' => fn (Warehouse $w) => $w->code],
            ['label' => 'Nama Gudang', 'value' => fn (Warehouse $w) => $w->name.($w->is_default ? ' (utama)' : '')],
            ['label' => 'Cabang', 'value' => fn (Warehouse $w) => $w->branch?->name],
            ['label' => 'Alamat', 'value' => fn (Warehouse $w) => $w->address],
            ['label' => 'Jumlah Produk', 'value' => fn (Warehouse $w) => $w->product_count, 'type' => 'qty'],
            ['label' => 'Total Stok', 'value' => fn (Warehouse $w) => $w->total_stock ?? 0, 'type' => 'qty'],
            ['label' => 'Status', 'value' => fn (Warehouse $w) => $w->is_active ? 'Aktif' : 'Nonaktif'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter([
            'Pencarian' => $this->filters['cari'] ?? null,
            'Cabang' => isset($this->filters['cabang']) ? Branch::withTrashed()->find($this->filters['cabang'])?->name : null,
            'Status' => isset($this->filters['status']) ? ucfirst($this->filters['status']) : null,
        ]);
    }
}
