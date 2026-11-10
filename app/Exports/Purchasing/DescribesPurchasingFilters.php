<?php

namespace App\Exports\Purchasing;

use App\Models\Supplier;
use App\Models\Warehouse;

/** Keterangan filter yang sama di semua daftar pembelian (kepala PDF). */
trait DescribesPurchasingFilters
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
            'Supplier' => isset($f['supplier']) ? Supplier::withTrashed()->find($f['supplier'])?->name : null,
            'Gudang' => isset($f['gudang']) ? Warehouse::withTrashed()->find($f['gudang'])?->name : null,
            'Status' => isset($f['status']) ? ($statusLabels[$f['status']] ?? $f['status']) : null,
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
