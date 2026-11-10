<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\Purchase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Pembayaran hutang ke supplier tercatat. Untuk modul Akuntansi (jurnal kas/bank & hutang). */
class SupplierPaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly Purchase $purchase,
        /** true = dibayar bersamaan dengan transaksinya (sudah termasuk jurnal transaksi tersebut). */
        public readonly bool $atCheckout = false,
    ) {}
}
