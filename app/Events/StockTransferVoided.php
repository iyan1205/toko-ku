<?php

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Transfer stok dibatalkan: stok kembali ke gudang asal. */
class StockTransferVoided implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StockTransfer $transfer) {}
}
