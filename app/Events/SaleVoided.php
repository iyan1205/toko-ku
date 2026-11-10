<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Penjualan dibatalkan (void): stok sudah kembali, pembayarannya ditandai batal, dan piutang
 * pelanggan dikoreksi. Untuk modul Akuntansi (jurnal balik).
 */
class SaleVoided implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Sale $sale) {}
}
