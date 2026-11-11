<?php

namespace App\Events;

use App\Models\StockMovement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Penyesuaian stok manual (termasuk barang rusak/hilang) tercatat di kartu stok. */
class StockAdjusted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StockMovement $movement) {}
}
