<?php

namespace App\Exports\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Exports\ListExport;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/** Faktur penjualan atau tagihan pembelian manual (filter 'type' wajib: sales|purchase). */
class InvoicesExport extends ListExport
{
    public function type(): InvoiceType
    {
        return InvoiceType::from($this->filters['type'] ?? 'sales');
    }

    public function documentTitle(): string
    {
        return $this->type() === InvoiceType::Sales ? 'Faktur Penjualan' : 'Tagihan Pembelian';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;
        $party = $this->type() === InvoiceType::Sales ? 'customer' : 'supplier';

        return Invoice::query()->ofType($this->type())
            ->with(["{$party}:id,name", 'creator:id,name'])
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$kata}%")->orWhere('reference', 'like', "%{$kata}%")->orWhere('description', 'like', "%{$kata}%")))
            ->when($f['pihak'] ?? null, fn (Builder $q, $v) => $q->where("{$party}_id", $v))
            ->when($f['status'] ?? null, fn (Builder $q, string $v) => match ($v) {
                'belum_lunas' => $q->outstanding(),
                'lunas' => $q->withStatus(DocumentStatus::Posted)->where('balance', '<=', 0),
                default => $q->where('status', $v),
            })
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('invoice_date', '<=', $v));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return ['number' => 'number', 'date' => 'invoice_date', 'due' => 'due_date', 'total' => 'total', 'balance' => 'balance'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (Invoice $i) => $i->number],
            ['label' => 'Tanggal', 'value' => fn (Invoice $i) => $i->invoice_date, 'type' => 'date'],
            ['label' => 'Jatuh Tempo', 'value' => fn (Invoice $i) => $i->due_date, 'type' => 'date'],
            ['label' => $this->type() === InvoiceType::Sales ? 'Pelanggan' : 'Supplier', 'value' => fn (Invoice $i) => $i->party()?->name],
            ['label' => 'Referensi', 'value' => fn (Invoice $i) => $i->reference],
            ['label' => 'Status', 'value' => fn (Invoice $i) => $i->documentStatus()->label()],
            ['label' => 'Total', 'value' => fn (Invoice $i) => $i->total, 'type' => 'money'],
            ['label' => 'Sisa', 'value' => fn (Invoice $i) => $i->balance, 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;
        $party = ! empty($f['pihak']) ? ($this->type() === InvoiceType::Sales ? Customer::withTrashed()->find($f['pihak']) : Supplier::withTrashed()->find($f['pihak'])) : null;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            $this->type() === InvoiceType::Sales ? 'Pelanggan' : 'Supplier' => $party?->name,
            'Status' => match ($f['status'] ?? null) {
                'belum_lunas' => 'Belum lunas',
                'lunas' => 'Lunas',
                null => null,
                default => DocumentStatus::tryFrom($f['status'])?->label(),
            },
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
