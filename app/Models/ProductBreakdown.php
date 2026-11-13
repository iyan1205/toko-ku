<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pemecahan bahan: source_quantity bahan (misal 1 pack Karkas 1) menghasilkan beberapa produk sekaligus
 * (outputs: 3 dada, 2 paha atas, 2 paha bawah, 2 sayap). Dijalankan lewat BreakdownService.
 */
class ProductBreakdown extends Model
{
    use RecordsActivity, TracksAuthors;

    public const COST_METHODS = [
        'quantity' => 'Rata per satuan hasil (tiap potong sama)',
        'price' => 'Sebanding harga jual hasil',
    ];

    protected $fillable = ['source_product_id', 'source_quantity', 'cost_method', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['source_quantity' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id')->withTrashed();
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProductBreakdownOutput::class)->orderBy('id');
    }

    /** "3 Ayam Original Dada, 2 Paha Atas, ..." */
    public function summary(): string
    {
        return $this->outputs->map(fn (ProductBreakdownOutput $o) => rtrim(rtrim(number_format((float) $o->quantity, 4, ',', '.'), '0'), ',').' '.$o->product?->name)->join(', ');
    }

    protected function activityLogName(): string
    {
        return 'inventori';
    }

    protected function activityLogLabel(): string
    {
        return 'Pemecahan bahan';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['source_product_id', 'source_quantity', 'cost_method', 'notes', 'is_active'];
    }
}
