<?php

namespace App\Models;

use App\Support\Uang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satuan lain satu produk (misal DUS = 12 PCS): isi, barcode, dan harga jual per satuan ini. */
class ProductUnit extends Model
{
    protected $fillable = ['unit_id', 'factor', 'barcode', 'selling_price'];

    protected function casts(): array
    {
        return ['factor' => 'decimal:4', 'selling_price' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Harga jual per satuan ini (sen): harga sendiri, atau harga dasar × isi. */
    public function priceSen(): int
    {
        return $this->selling_price !== null
            ? Uang::sen($this->selling_price)
            : (int) round(Uang::sen($this->product->selling_price) * (float) $this->factor);
    }

    /** Jumlah satuan dasar (milli) untuk $milli satuan ini. */
    public function toBaseMilli(int $milli): int
    {
        return (int) round($milli * (float) $this->factor);
    }
}
