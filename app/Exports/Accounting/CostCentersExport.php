<?php

namespace App\Exports\Accounting;

use App\Exports\ListExport;
use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Builder;

/** Daftar cost center / departemen beserta jumlah baris jurnal yang memakainya. */
class CostCentersExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Cost Center';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;

        return CostCenter::query()
            ->with('department:id,name')
            ->withCount('lines')
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('code', 'like', "%{$kata}%")->orWhere('name', 'like', "%{$kata}%")))
            ->when($f['status'] ?? null, fn (Builder $q, string $v) => $q->where('is_active', $v === 'aktif'));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('code');
    }

    protected function sorts(): array
    {
        return ['code' => 'code', 'name' => 'name'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Kode', 'value' => fn (CostCenter $c) => $c->code],
            ['label' => 'Nama', 'value' => fn (CostCenter $c) => $c->name],
            ['label' => 'Departemen SDM', 'value' => fn (CostCenter $c) => $c->department?->name],
            ['label' => 'Deskripsi', 'value' => fn (CostCenter $c) => $c->description],
            ['label' => 'Status', 'value' => fn (CostCenter $c) => $c->is_active ? 'Aktif' : 'Nonaktif'],
            ['label' => 'Baris Jurnal', 'value' => fn (CostCenter $c) => $c->lines_count, 'type' => 'qty'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter([
            'Pencarian' => $this->filters['cari'] ?? null,
            'Status' => isset($this->filters['status']) ? ucfirst($this->filters['status']) : null,
        ]);
    }
}
