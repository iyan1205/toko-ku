<?php

namespace App\Exports\Purchasing;

use App\Enums\PurchaseStatus;
use App\Exports\ListExport;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class GoodsReceiptsExport extends ListExport
{
    use DescribesPurchasingFilters;

    public function documentTitle(): string
    {
        return 'Penerimaan Barang';
    }

    protected function baseQuery(): Builder
    {
        return GoodsReceipt::query()
            ->with(['supplier:id,name', 'warehouse:id,name', 'purchaseOrder:id,number', 'purchase:id,number,grand_total', 'user:id,name'])
            ->withCount('items')
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('received_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'received_date',
            'supplier' => fn (Builder $q, string $dir) => $q->orderBy(Supplier::withTrashed()->select('name')->whereColumn('suppliers.id', 'goods_receipts.supplier_id'), $dir),
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (GoodsReceipt $r) => $r->number],
            ['label' => 'Tanggal Terima', 'value' => fn (GoodsReceipt $r) => $r->received_date, 'type' => 'date'],
            ['label' => 'Purchase Order', 'value' => fn (GoodsReceipt $r) => $r->purchaseOrder?->number],
            ['label' => 'Supplier', 'value' => fn (GoodsReceipt $r) => $r->supplier?->name],
            ['label' => 'Gudang', 'value' => fn (GoodsReceipt $r) => $r->warehouse?->name],
            ['label' => 'Surat Jalan', 'value' => fn (GoodsReceipt $r) => $r->delivery_note_number],
            ['label' => 'Jumlah Produk', 'value' => fn (GoodsReceipt $r) => $r->items_count, 'type' => 'qty'],
            ['label' => 'Faktur', 'value' => fn (GoodsReceipt $r) => $r->purchase?->number],
            ['label' => 'Nilai', 'value' => fn (GoodsReceipt $r) => $r->purchase?->grand_total, 'type' => 'money'],
            ['label' => 'Status', 'value' => fn (GoodsReceipt $r) => $r->status->label()],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary(collect(PurchaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all());
    }
}
