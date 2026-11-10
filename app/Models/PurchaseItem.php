<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id', 'product_id', 'product_name', 'sku', 'unit_name',
        'quantity', 'unit_price', 'discount', 'line_total',
    ];

    /** returned_quantity hanya diubah PurchaseReturnService (tidak fillable). */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /** Jumlah yang masih bisa diretur. */
    public function returnableQuantity(): float
    {
        return round((float) $this->quantity - (float) $this->returned_quantity, 3);
    }

    /** Harga bersih per unit (setelah diskon baris), dasar nilai retur. */
    public function netUnitPrice(): float
    {
        return (float) $this->quantity > 0 ? round((float) $this->line_total / (float) $this->quantity, 2) : 0.0;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
