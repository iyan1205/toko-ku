<?php

namespace App\Events;

use App\Models\SaleReturn;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Retur penjualan disetujui: stok kembali ke gudang, piutang transaksi berkurang sebesar
 * receivable_reduction, dan refund_amount dikembalikan ke pelanggan (baris payments milik
 * retur ini). Untuk modul Akuntansi (jurnal retur penjualan dan HPP balik).
 */
class SaleReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SaleReturn $saleReturn) {}
}
