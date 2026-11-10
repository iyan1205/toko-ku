<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok satu produk di satu gudang. Hanya StockService yang mengubah `quantity`,
 * bersamaan dengan products.stock (total semua gudang) dan kartu stok.
 */
class WarehouseStock extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
