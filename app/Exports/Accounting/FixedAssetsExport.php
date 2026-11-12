<?php

namespace App\Exports\Accounting;

use App\Exports\ListExport;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Builder;

/** Daftar aset tetap dengan nilai buku. */
class FixedAssetsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Daftar Aset Tetap';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;

        return FixedAsset::query()
            ->with(['category:id,name', 'employee:id,name', 'branch:id,name'])
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('code', 'like', "%{$kata}%")->orWhere('name', 'like', "%{$kata}%")->orWhere('serial_number', 'like', "%{$kata}%")->orWhere('location', 'like', "%{$kata}%")))
            ->when($f['kategori'] ?? null, fn (Builder $q, $v) => $q->where('asset_category_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('code');
    }

    protected function sorts(): array
    {
        return ['code' => 'code', 'name' => 'name', 'date' => 'acquisition_date', 'cost' => 'acquisition_cost'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Kode', 'value' => fn (FixedAsset $a) => $a->code],
            ['label' => 'Nama', 'value' => fn (FixedAsset $a) => $a->name],
            ['label' => 'Kategori', 'value' => fn (FixedAsset $a) => $a->category?->name],
            ['label' => 'Lokasi / Penanggung Jawab', 'value' => fn (FixedAsset $a) => $a->holderLabel()],
            ['label' => 'Tgl Perolehan', 'value' => fn (FixedAsset $a) => $a->acquisition_date, 'type' => 'date'],
            ['label' => 'Harga Perolehan', 'value' => fn (FixedAsset $a) => $a->acquisition_cost, 'type' => 'money'],
            ['label' => 'Akumulasi', 'value' => fn (FixedAsset $a) => $a->accumulated_depreciation, 'type' => 'money'],
            ['label' => 'Nilai Buku', 'value' => fn (FixedAsset $a) => $a->isDisposed() ? 0 : $a->bookValue(), 'type' => 'money'],
            ['label' => 'Status', 'value' => fn (FixedAsset $a) => $a->isDisposed() ? 'Dilepas' : 'Aktif'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter([
            'Pencarian' => $this->filters['cari'] ?? null,
            'Kategori' => isset($this->filters['kategori']) ? AssetCategory::withTrashed()->find($this->filters['kategori'])?->name : null,
            'Status' => match ($this->filters['status'] ?? null) {
                'active' => 'Aktif', 'disposed' => 'Dilepas', default => null
            },
        ]);
    }
}
