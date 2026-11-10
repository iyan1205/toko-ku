<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Baris PO. received_quantity hanya diubah GoodsReceiptService (tidak fillable). */
class PurchaseOrderItem extends Model
{
    protected $fillable = ['product_id', 'product_name', 'sku', 'unit_name', 'quantity', 'unit_price', 'discount', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    /** Jumlah yang belum diterima. */
    public function remainingQuantity(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->received_quantity, 3));
    }
}
