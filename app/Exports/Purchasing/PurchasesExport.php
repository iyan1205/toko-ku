<?php

namespace App\Exports\Purchasing;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Exports\ListExport;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/** Faktur pembelian (langsung dan dari penerimaan barang). */
class PurchasesExport extends ListExport
{
    use DescribesPurchasingFilters;

    public function documentTitle(): string
    {
        return 'Faktur Pembelian';
    }

    protected function baseQuery(): Builder
    {
        return Purchase::query()
            ->with(['supplier:id,name', 'warehouse:id,name', 'purchaseOrder:id,number'])
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('purchase_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'purchase_date',
            'supplier' => fn (Builder $q, string $dir) => $q->orderBy(Supplier::withTrashed()->select('name')->whereColumn('suppliers.id', 'purchases.supplier_id'), $dir),
            'total' => 'grand_total',
            'balance' => 'balance',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (Purchase $p) => $p->number],
            ['label' => 'Tanggal', 'value' => fn (Purchase $p) => $p->purchase_date, 'type' => 'date'],
            ['label' => 'Supplier', 'value' => fn (Purchase $p) => $p->supplier?->name],
            ['label' => 'No. Faktur Supplier', 'value' => fn (Purchase $p) => $p->supplier_invoice_number],
            ['label' => 'Purchase Order', 'value' => fn (Purchase $p) => $p->purchaseOrder?->number],
            ['label' => 'Gudang', 'value' => fn (Purchase $p) => $p->warehouse?->name],
            ['label' => 'Subtotal', 'value' => fn (Purchase $p) => $p->subtotal, 'type' => 'money'],
            ['label' => 'Diskon', 'value' => fn (Purchase $p) => (float) $p->item_discount + (float) $p->discount, 'type' => 'money'],
            ['label' => 'Pajak + Biaya', 'value' => fn (Purchase $p) => (float) $p->tax + (float) $p->other_cost, 'type' => 'money'],
            ['label' => 'Total', 'value' => fn (Purchase $p) => $p->grand_total, 'type' => 'money'],
            ['label' => 'Sisa Hutang', 'value' => fn (Purchase $p) => $p->balance, 'type' => 'money'],
            ['label' => 'Pembayaran', 'value' => fn (Purchase $p) => $p->payment_status->label()],
            ['label' => 'Status', 'value' => fn (Purchase $p) => $p->status->label()],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary(collect(PurchaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()) + array_filter([
            'Pembayaran' => isset($this->filters['pembayaran']) ? PaymentStatus::tryFrom($this->filters['pembayaran'])?->label() : null,
        ]);
    }
}
