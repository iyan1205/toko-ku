<?php

namespace App\Exports\Accounting;

use App\Exports\ListExport;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Database\Eloquent\Builder;

/** Jurnal umum: manual dan otomatis. */
class JournalsExport extends ListExport
{
    public const SOURCES = [
        'manual' => 'Manual',
        'otomatis' => 'Semua jurnal otomatis',
        'sale' => 'Penjualan',
        'sale_return' => 'Retur penjualan',
        'purchase' => 'Pembelian',
        'purchase_return' => 'Retur pembelian',
        'payment' => 'Pembayaran',
    ];

    public function documentTitle(): string
    {
        return 'Jurnal Umum';
    }

    protected function baseQuery(): Builder
    {
        return Journal::query()->with('user:id,name')->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('journal_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return ['number' => 'number', 'date' => 'journal_date', 'total' => 'total'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (Journal $j) => $j->number],
            ['label' => 'Tanggal', 'value' => fn (Journal $j) => $j->journal_date, 'type' => 'date'],
            ['label' => 'Keterangan', 'value' => fn (Journal $j) => $j->description],
            ['label' => 'Sumber', 'value' => fn (Journal $j) => $j->sourceLabel()],
            ['label' => 'Total', 'value' => fn (Journal $j) => $j->total, 'type' => 'money'],
            ['label' => 'Dibuat Oleh', 'value' => fn (Journal $j) => $j->user?->name],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            'Sumber' => self::SOURCES[$f['sumber'] ?? ''] ?? null,
            'Akun' => isset($f['akun']) ? Account::withTrashed()->find($f['akun'])?->label() : null,
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
