<?php

namespace App\Exports\Purchasing;

use App\Enums\PurchaseReturnStatus;
use App\Exports\ListExport;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class PurchaseReturnsExport extends ListExport
{
    use DescribesPurchasingFilters;

    public function documentTitle(): string
    {
        return 'Retur Pembelian';
    }

    protected function baseQuery(): Builder
    {
        return PurchaseReturn::query()
            ->with(['supplier:id,name', 'warehouse:id,name', 'purchase:id,number', 'user:id,name'])
            ->withCount('items')
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('return_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'return_date',
            'supplier' => fn (Builder $q, string $dir) => $q->orderBy(Supplier::withTrashed()->select('name')->whereColumn('suppliers.id', 'purchase_returns.supplier_id'), $dir),
            'total' => 'total',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (PurchaseReturn $r) => $r->number],
            ['label' => 'Tanggal', 'value' => fn (PurchaseReturn $r) => $r->return_date, 'type' => 'date'],
            ['label' => 'Faktur', 'value' => fn (PurchaseReturn $r) => $r->purchase?->number],
            ['label' => 'Supplier', 'value' => fn (PurchaseReturn $r) => $r->supplier?->name],
            ['label' => 'Gudang', 'value' => fn (PurchaseReturn $r) => $r->warehouse?->name],
            ['label' => 'Alasan', 'value' => fn (PurchaseReturn $r) => $r->reason],
            ['label' => 'Nilai Retur', 'value' => fn (PurchaseReturn $r) => $r->total, 'type' => 'money'],
            ['label' => 'Refund dari Supplier', 'value' => fn (PurchaseReturn $r) => $r->refund_amount, 'type' => 'money'],
            ['label' => 'Status', 'value' => fn (PurchaseReturn $r) => $r->status->label()],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary(collect(PurchaseReturnStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all());
    }
}
