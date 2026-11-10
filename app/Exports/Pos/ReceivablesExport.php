<?php

namespace App\Exports\Pos;

use App\Exports\ListExport;
use App\Models\Customer;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Piutang pelanggan: penjualan selesai yang masih punya sisa piutang, terlama dulu.
 * Filter umur: dari tanggal transaksi (penjualan kasir tidak punya jatuh tempo).
 */
class ReceivablesExport extends ListExport
{
    use DescribesPosFilters;

    public const AGE_FILTERS = [
        'baru' => '0–7 hari',
        'bulan' => '8–30 hari',
        'lama' => 'Lebih dari 30 hari',
    ];

    public function documentTitle(): string
    {
        return 'Piutang Pelanggan';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;
        $today = CarbonImmutable::today();

        return Sale::query()
            ->with(['customer:id,name,phone', 'user:id,name'])
            ->outstanding()
            ->filter(array_intersect_key($f, array_flip(['cari', 'pelanggan', 'dari', 'sampai'])))
            ->when($f['umur'] ?? null, fn (Builder $q, string $v) => match ($v) {
                'baru' => $q->whereDate('sold_at', '>=', $today->subDays(7)),
                'bulan' => $q->whereDate('sold_at', '<', $today->subDays(7))->whereDate('sold_at', '>=', $today->subDays(30)),
                'lama' => $q->whereDate('sold_at', '<', $today->subDays(30)),
                default => $q,
            });
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('sold_at')->orderBy('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'sold_at',
            'balance' => 'balance',
            'customer' => fn (Builder $q, string $dir) => $q->orderBy(Customer::withTrashed()->select('name')->whereColumn('customers.id', 'sales.customer_id'), $dir),
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Penjualan', 'value' => fn (Sale $s) => $s->number],
            ['label' => 'Tanggal', 'value' => fn (Sale $s) => $s->sold_at, 'type' => 'date'],
            ['label' => 'Pelanggan', 'value' => fn (Sale $s) => $s->customer?->name],
            ['label' => 'Telepon', 'value' => fn (Sale $s) => $s->customer?->phone],
            ['label' => 'Kasir', 'value' => fn (Sale $s) => $s->user?->name],
            ['label' => 'Total', 'value' => fn (Sale $s) => $s->grand_total, 'type' => 'money'],
            ['label' => 'Dibayar', 'value' => fn (Sale $s) => $s->paid_amount, 'type' => 'money'],
            ['label' => 'Retur', 'value' => fn (Sale $s) => $s->returned_amount, 'type' => 'money'],
            ['label' => 'Sisa Piutang', 'value' => fn (Sale $s) => $s->balance, 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary() + array_filter([
            'Umur piutang' => self::AGE_FILTERS[$this->filters['umur'] ?? ''] ?? null,
        ]);
    }
}
