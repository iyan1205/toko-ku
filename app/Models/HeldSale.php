<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Keranjang yang ditahan kasir. Hanya menyimpan ID produk, jumlah, dan diskon; harga selalu diambil ulang saat dipanggil. */
class HeldSale extends Model
{
    public const MAKS_PER_KASIR = 20;

    protected $fillable = ['user_id', 'customer_id', 'label', 'cart'];

    protected function casts(): array
    {
        return ['cart' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
