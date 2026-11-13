<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu hasil pemecahan bahan: produk dan jumlahnya per sekali pecah. */
class ProductBreakdownOutput extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function breakdown(): BelongsTo
    {
        return $this->belongsTo(ProductBreakdown::class, 'product_breakdown_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
