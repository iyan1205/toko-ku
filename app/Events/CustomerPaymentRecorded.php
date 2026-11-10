<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Uang dari pelanggan diterima untuk sebuah penjualan: pembayaran saat transaksi (satu event per
 * metode), bayar di muka, atau pelunasan piutang. Untuk modul Akuntansi (jurnal kas/bank & piutang).
 */
class CustomerPaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly Sale $sale,
        /** true = dibayar bersamaan dengan transaksinya (sudah termasuk jurnal transaksi tersebut). */
        public readonly bool $atCheckout = false,
    ) {}
}
