<?php

namespace App\Exports\Pos;

use App\Enums\PaymentMethod;
use App\Exports\ListExport;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat transaksi kasir. Filter kasir dipaksa ke pengguna sendiri oleh controller bila
 * pengguna hanya punya izin pos.transactions.view-own.
 */
class SalesExport extends ListExport
{
    use DescribesPosFilters;

    public const STATUSES = ['completed' => 'Selesai', 'voided' => 'Dibatalkan'];

    public function documentTitle(): string
    {
        return 'Riwayat Transaksi';
    }

    protected function baseQuery(): Builder
    {
        return Sale::query()->with(['customer:id,name', 'user:id,name', 'warehouse:id,name'])->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('sold_at')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'sold_at',
            'total' => 'grand_total',
            'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::withTrashed()->select('name')->whereColumn('customers.id', 'sales.customer_id'), $dir),
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (Sale $s) => $s->number],
            ['label' => 'Waktu', 'value' => fn (Sale $s) => $s->sold_at, 'type' => 'datetime'],
            ['label' => 'Kasir', 'value' => fn (Sale $s) => $s->user?->name],
            ['label' => 'Pelanggan', 'value' => fn (Sale $s) => $s->customer?->name],
            ['label' => 'Gudang', 'value' => fn (Sale $s) => $s->warehouse?->name],
            ['label' => 'Metode', 'value' => fn (Sale $s) => $s->payment_method->label()],
            ['label' => 'Subtotal', 'value' => fn (Sale $s) => $s->subtotal, 'type' => 'money'],
            ['label' => 'Diskon', 'value' => fn (Sale $s) => (float) $s->item_discount + (float) $s->discount, 'type' => 'money'],
            ['label' => 'Pajak', 'value' => fn (Sale $s) => $s->tax, 'type' => 'money'],
            ['label' => 'Total', 'value' => fn (Sale $s) => $s->grand_total, 'type' => 'money'],
            ['label' => 'Retur', 'value' => fn (Sale $s) => $s->returned_amount, 'type' => 'money'],
            ['label' => 'Sisa Piutang', 'value' => fn (Sale $s) => $s->balance, 'type' => 'money'],
            ['label' => 'Status', 'value' => fn (Sale $s) => $s->dibatalkan() ? 'Dibatalkan' : $s->payment_status->labelPenjualan()],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary(self::STATUSES) + array_filter([
            'Metode' => isset($this->filters['metode']) ? PaymentMethod::tryFrom($this->filters['metode'])?->label() : null,
        ]);
    }
}
