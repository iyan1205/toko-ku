<?php

namespace App\Events;

use App\Models\Purchase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Faktur pembelian dibatalkan (langsung, atau lewat pembatalan penerimaan barang): stok sudah
 * dikembalikan dan pembayarannya ditandai batal. Untuk modul Akuntansi (jurnal balik).
 */
class PurchaseVoided implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Purchase $purchase) {}
}
