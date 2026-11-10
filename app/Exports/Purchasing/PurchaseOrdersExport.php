<?php

namespace App\Exports\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exports\ListExport;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrdersExport extends ListExport
{
    use DescribesPurchasingFilters;

    public function documentTitle(): string
    {
        return 'Purchase Order';
    }

    protected function baseQuery(): Builder
    {
        return PurchaseOrder::query()
            ->with(['supplier:id,name', 'warehouse:id,name', 'user:id,name'])
            ->withSum('items as ordered_quantity', 'quantity')
            ->withSum('items as received_total', 'received_quantity')
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('order_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'order_date',
            'supplier' => fn (Builder $q, string $dir) => $q->orderBy(Supplier::withTrashed()->select('name')->whereColumn('suppliers.id', 'purchase_orders.supplier_id'), $dir),
            'total' => 'grand_total',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (PurchaseOrder $o) => $o->number],
            ['label' => 'Tanggal', 'value' => fn (PurchaseOrder $o) => $o->order_date, 'type' => 'date'],
            ['label' => 'Supplier', 'value' => fn (PurchaseOrder $o) => $o->supplier?->name],
            ['label' => 'Gudang Tujuan', 'value' => fn (PurchaseOrder $o) => $o->warehouse?->name],
            ['label' => 'Perkiraan Tiba', 'value' => fn (PurchaseOrder $o) => $o->expected_date, 'type' => 'date'],
            ['label' => 'Total', 'value' => fn (PurchaseOrder $o) => $o->grand_total, 'type' => 'money'],
            ['label' => 'Diterima (%)', 'value' => fn (PurchaseOrder $o) => $this->receivedPercent($o), 'type' => 'qty'],
            ['label' => 'Status', 'value' => fn (PurchaseOrder $o) => $o->status->label()],
            ['label' => 'Dibuat Oleh', 'value' => fn (PurchaseOrder $o) => $o->user?->name],
        ];
    }

    /** Persentase kuantitas yang sudah diterima. */
    public function receivedPercent(PurchaseOrder $order): float
    {
        $ordered = (float) $order->ordered_quantity;

        return $ordered > 0 ? round((float) $order->received_total / $ordered * 100, 1) : 0.0;
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary(collect(PurchaseOrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all());
    }
}
