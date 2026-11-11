<?php

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Transfer stok antar gudang selesai (antar cabang dijurnal lewat Rekening Antar Cabang). */
class StockTransferred implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StockTransfer $transfer) {}
}
