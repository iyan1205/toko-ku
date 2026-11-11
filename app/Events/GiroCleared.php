<?php

namespace App\Events;

use App\Models\Settlement;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Giro/cek pelunasan cair ke rekening. Untuk jurnal pemindahan dari akun Giro ke rekening. */
class GiroCleared implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Settlement $settlement, public readonly ?User $user = null) {}
}
