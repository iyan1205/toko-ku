<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris faktur manual: keterangan, jumlah x harga, akun pendapatan (penjualan) atau beban/aset (tagihan). */
class InvoiceLine extends Model
{
    protected $fillable = ['account_id', 'cost_center_id', 'description', 'quantity', 'unit_price', 'amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }
}
