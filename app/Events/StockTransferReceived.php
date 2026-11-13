<?php

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Transfer antar cabang diterima di gudang tujuan (jurnal masuk di cabang penerima). */
class StockTransferReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StockTransfer $transfer) {}
}
