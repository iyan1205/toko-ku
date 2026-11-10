<?php

namespace App\Events;

use App\Models\GoodsReceipt;
use App\Models\Purchase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Barang dari supplier masuk ke gudang dan hutangnya tercatat: pembelian langsung (tanpa PO)
 * atau penerimaan barang dari PO. Disiapkan untuk modul Akuntansi (jurnal persediaan & hutang);
 * belum ada listener. Dikirim setelah transaksi database berhasil.
 *
 * $purchase = faktur/hutang (nilai), $receipt = dokumen penerimaan (null untuk pembelian langsung).
 */
class GoodsReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Purchase $purchase,
        public readonly ?GoodsReceipt $receipt = null,
    ) {}
}
