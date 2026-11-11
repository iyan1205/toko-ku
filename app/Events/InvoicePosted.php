<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Faktur penjualan/tagihan pembelian manual diposting. Untuk jurnal otomatis. */
class InvoicePosted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Invoice $invoice) {}
}
