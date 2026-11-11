<?php

namespace App\Events;

use App\Models\Settlement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Pelunasan piutang/hutang atau nota tersimpan; sisa faktur sudah berubah. Untuk jurnal otomatis. */
class SettlementPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Settlement $settlement) {}
}
