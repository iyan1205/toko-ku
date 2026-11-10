<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Penjualan kasir tersimpan: stok sudah keluar dari gudang penjualan. Untuk modul Akuntansi
 * (jurnal penjualan, pajak, dan HPP dari sale_items.cost_price). Uang yang diterima dikirim
 * terpisah lewat CustomerPaymentRecorded, satu per baris pembayaran. Dikirim setelah
 * transaksi database berhasil.
 */
class SaleCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Sale $sale) {}
}
