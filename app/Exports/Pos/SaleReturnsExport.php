<?php

namespace App\Exports\Pos;

use App\Enums\SaleReturnStatus;
use App\Exports\ListExport;
use App\Models\Customer;
use App\Models\SaleReturn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar retur penjualan. Filter kasir (pembuat) dipaksa ke pengguna sendiri oleh controller
 * bila pengguna tidak boleh melihat semua retur.
 */
class SaleReturnsExport extends ListExport
{
    use DescribesPosFilters;

    public function documentTitle(): string
    {
        return 'Retur Penjualan';
    }

    protected function baseQuery(): Builder
    {
        return SaleReturn::query()->with(['sale:id,number', 'customer:id,name', 'user:id,name'])->filter($this->filters);
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
            'total' => 'total',
            'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::withTrashed()->select('name')->whereColumn('customers.id', 'sale_returns.customer_id'), $dir),
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (SaleReturn $r) => $r->number],
            ['label' => 'Tanggal', 'value' => fn (SaleReturn $r) => $r->return_date, 'type' => 'date'],
            ['label' => 'Penjualan', 'value' => fn (SaleReturn $r) => $r->sale?->number],
            ['label' => 'Pelanggan', 'value' => fn (SaleReturn $r) => $r->customer?->name],
            ['label' => 'Diajukan Oleh', 'value' => fn (SaleReturn $r) => $r->user?->name],
            ['label' => 'Alasan', 'value' => fn (SaleReturn $r) => $r->reason],
            ['label' => 'Nilai Retur', 'value' => fn (SaleReturn $r) => $r->total, 'type' => 'money'],
            ['label' => 'Kurangi Piutang', 'value' => fn (SaleReturn $r) => $r->receivable_reduction, 'type' => 'money'],
            ['label' => 'Uang Kembali', 'value' => fn (SaleReturn $r) => $r->refund_amount, 'type' => 'money'],
            ['label' => 'Status', 'value' => fn (SaleReturn $r) => $r->status->label()],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary(collect(SaleReturnStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all());
    }
}
