<?php

namespace App\Exports\Inventory;

use App\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Daftar data master sederhana (nama, deskripsi, jumlah produk): kategori dan satuan. */
abstract class MasterDataExport extends ListExport
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    protected function baseQuery(): Builder
    {
        return ($this->model())::query()
            ->withCount('products')
            ->when($this->filters['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$kata}%")->orWhere('description', 'like', "%{$kata}%")));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('name');
    }

    protected function sorts(): array
    {
        return ['name' => 'name', 'products' => 'products_count'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nama', 'value' => fn (Model $m) => $m->getAttribute('name')],
            ['label' => 'Deskripsi', 'value' => fn (Model $m) => $m->getAttribute('description')],
            ['label' => 'Jumlah Produk', 'value' => fn (Model $m) => $m->getAttribute('products_count'), 'type' => 'qty'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter(['Pencarian' => $this->filters['cari'] ?? null]);
    }
}
