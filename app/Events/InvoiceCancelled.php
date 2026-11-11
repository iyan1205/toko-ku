<?php

namespace App\Events;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Faktur manual yang sudah diposting dibatalkan. Untuk jurnal pembalik. */
class InvoiceCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Invoice $invoice, public readonly ?User $user = null) {}
}
