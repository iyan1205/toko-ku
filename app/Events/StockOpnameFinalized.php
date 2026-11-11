<?php

namespace App\Events;

use App\Models\StockOpname;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Stok opname diselesaikan: selisihnya sudah dibukukan ke stok (dijurnal sebagai selisih persediaan). */
class StockOpnameFinalized implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StockOpname $opname) {}
}
