<?php

namespace App\Exports\Purchasing;

use App\Exports\ListExport;
use App\Models\Purchase;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hutang supplier: faktur selesai yang masih punya sisa hutang.
 * Filter jatuh_tempo: lewat (sudah lewat/hari ini), minggu (7 hari ke depan), belum, tanpa (tanpa tanggal).
 */
class PayablesExport extends ListExport
{
    use DescribesPurchasingFilters;

    public const DUE_FILTERS = [
        'lewat' => 'Sudah jatuh tempo',
        'minggu' => 'Jatuh tempo 7 hari ke depan',
        'belum' => 'Belum jatuh tempo',
        'tanpa' => 'Tanpa jatuh tempo',
    ];

    public function documentTitle(): string
    {
        return 'Hutang Supplier';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;
        $today = CarbonImmutable::today();

        return Purchase::query()
            ->with(['supplier:id,name', 'purchaseOrder:id,number'])
            ->outstanding()
            ->filter(array_intersect_key($f, array_flip(['cari', 'supplier', 'dari', 'sampai'])))
            ->when($f['jatuh_tempo'] ?? null, fn (Builder $q, string $v) => match ($v) {
                'lewat' => $q->whereDate('due_date', '<=', $today),
                'minggu' => $q->whereDate('due_date', '>', $today)->whereDate('due_date', '<=', $today->addDays(7)),
                'belum' => $q->whereDate('due_date', '>', $today),
                'tanpa' => $q->whereNull('due_date'),
                default => $q,
            });
    }

    protected function defaultOrder(Builder $query): void
    {
        // Jatuh tempo terdekat dulu; faktur tanpa jatuh tempo di akhir.
        $query->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'date' => 'purchase_date',
            'due' => 'due_date',
            'supplier' => fn (Builder $q, string $dir) => $q->orderBy(Supplier::withTrashed()->select('name')->whereColumn('suppliers.id', 'purchases.supplier_id'), $dir),
            'balance' => 'balance',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Faktur', 'value' => fn (Purchase $p) => $p->number],
            ['label' => 'No. Faktur Supplier', 'value' => fn (Purchase $p) => $p->supplier_invoice_number],
            ['label' => 'Supplier', 'value' => fn (Purchase $p) => $p->supplier?->name],
            ['label' => 'Tanggal', 'value' => fn (Purchase $p) => $p->purchase_date, 'type' => 'date'],
            ['label' => 'Jatuh Tempo', 'value' => fn (Purchase $p) => $p->due_date, 'type' => 'date'],
            ['label' => 'Total', 'value' => fn (Purchase $p) => $p->grand_total, 'type' => 'money'],
            ['label' => 'Dibayar', 'value' => fn (Purchase $p) => $p->paid_amount, 'type' => 'money'],
            ['label' => 'Retur', 'value' => fn (Purchase $p) => $p->returned_amount, 'type' => 'money'],
            ['label' => 'Sisa Hutang', 'value' => fn (Purchase $p) => $p->balance, 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary() + array_filter([
            'Jatuh tempo' => self::DUE_FILTERS[$this->filters['jatuh_tempo'] ?? ''] ?? null,
        ]);
    }
}
