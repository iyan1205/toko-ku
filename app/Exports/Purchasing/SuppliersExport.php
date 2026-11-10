<?php

namespace App\Exports\Purchasing;

use App\Enums\PurchaseStatus;
use App\Exports\ListExport;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/** Daftar supplier beserta total pembelian dan sisa hutangnya (faktur yang tidak dibatalkan). */
class SuppliersExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Daftar Supplier';
    }

    protected function baseQuery(): Builder
    {
        $selesai = fn (Builder $q) => $q->where('status', PurchaseStatus::Completed->value);

        return Supplier::query()
            ->withSum(['purchases as purchase_total' => $selesai], 'grand_total')
            ->withSum(['purchases as debt_total' => $selesai], 'balance')
            ->cari($this->filters['cari'] ?? null)
            ->when(($this->filters['hutang'] ?? null) === 'ada', fn (Builder $q) => $q->whereHas('purchases', fn (Builder $p) => $p->outstanding()));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('name');
    }

    protected function sorts(): array
    {
        return [
            'code' => 'code',
            'name' => 'name',
            'purchases' => 'purchase_total',
            'debt' => 'debt_total',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Kode', 'value' => fn (Supplier $s) => $s->code],
            ['label' => 'Nama Supplier', 'value' => fn (Supplier $s) => $s->name],
            ['label' => 'Kontak', 'value' => fn (Supplier $s) => $s->contact_name],
            ['label' => 'Telepon', 'value' => fn (Supplier $s) => $s->phone],
            ['label' => 'Email', 'value' => fn (Supplier $s) => $s->email],
            ['label' => 'NPWP', 'value' => fn (Supplier $s) => $s->npwp],
            ['label' => 'Alamat', 'value' => fn (Supplier $s) => $s->address],
            ['label' => 'Total Pembelian', 'value' => fn (Supplier $s) => $s->purchase_total ?? 0, 'type' => 'money'],
            ['label' => 'Sisa Hutang', 'value' => fn (Supplier $s) => $s->debt_total ?? 0, 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter([
            'Pencarian' => $this->filters['cari'] ?? null,
            'Hutang' => ($this->filters['hutang'] ?? null) === 'ada' ? 'Masih punya hutang' : null,
        ]);
    }
}
