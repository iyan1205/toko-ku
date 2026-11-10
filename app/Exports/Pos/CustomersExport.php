<?php

namespace App\Exports\Pos;

use App\Enums\PurchaseStatus;
use App\Exports\ListExport;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/** Daftar pelanggan beserta total belanja (transaksi yang tidak dibatalkan) dan saldo piutangnya. */
class CustomersExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Daftar Pelanggan';
    }

    protected function baseQuery(): Builder
    {
        $selesai = fn (Builder $q) => $q->where('status', PurchaseStatus::Completed->value);

        return Customer::query()
            ->withCount(['sales as sales_count' => $selesai])
            ->withSum(['sales as sales_total' => $selesai], 'grand_total')
            ->cari($this->filters['cari'] ?? null)
            ->when(($this->filters['piutang'] ?? null) === 'ada', fn (Builder $q) => $q->where('receivable_balance', '>', 0));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('is_default')->orderBy('name');
    }

    protected function sorts(): array
    {
        return [
            'code' => 'code',
            'name' => 'name',
            'sales' => 'sales_total',
            'receivable' => 'receivable_balance',
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Kode', 'value' => fn (Customer $c) => $c->code],
            ['label' => 'Nama Pelanggan', 'value' => fn (Customer $c) => $c->name],
            ['label' => 'Telepon', 'value' => fn (Customer $c) => $c->phone],
            ['label' => 'Email', 'value' => fn (Customer $c) => $c->email],
            ['label' => 'Alamat', 'value' => fn (Customer $c) => $c->address],
            ['label' => 'Jumlah Transaksi', 'value' => fn (Customer $c) => $c->sales_count ?? 0, 'type' => 'qty'],
            ['label' => 'Total Belanja', 'value' => fn (Customer $c) => $c->sales_total ?? 0, 'type' => 'money'],
            ['label' => 'Saldo Piutang', 'value' => fn (Customer $c) => $c->receivable_balance, 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter([
            'Pencarian' => $this->filters['cari'] ?? null,
            'Piutang' => ($this->filters['piutang'] ?? null) === 'ada' ? 'Masih punya piutang' : null,
        ]);
    }
}
