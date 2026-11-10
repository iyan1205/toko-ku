<?php

namespace App\Exports\Pos;

use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;

/** Keterangan filter yang sama di semua daftar Kasir (kepala PDF). */
trait DescribesPosFilters
{
    /**
     * @param  array<string, string>  $statusLabels  nilai => label
     * @return array<string, string>
     */
    protected function commonSummary(array $statusLabels = []): array
    {
        $f = $this->filters;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            'Kasir' => isset($f['kasir']) ? User::withTrashed()->find($f['kasir'])?->name : null,
            'Pelanggan' => isset($f['pelanggan']) ? Customer::withTrashed()->find($f['pelanggan'])?->name : null,
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : null,
            'Status' => isset($f['status']) ? ($statusLabels[$f['status']] ?? $f['status']) : null,
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
