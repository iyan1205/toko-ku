<?php

namespace App\Events;

use App\Models\PurchaseReturn;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Retur pembelian disetujui: stok keluar dan hutang faktur berkurang (refund_amount = kelebihan
 * yang harus dikembalikan supplier). Untuk modul Akuntansi (jurnal retur).
 */
class PurchaseReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PurchaseReturn $purchaseReturn) {}
}
