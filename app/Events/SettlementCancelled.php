<?php

namespace App\Events;

use App\Models\Settlement;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Pelunasan dibatalkan (atau giro ditolak): sisa faktur sudah kembali. Untuk jurnal pembalik. */
class SettlementCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Settlement $settlement, public readonly ?User $user = null) {}
}
