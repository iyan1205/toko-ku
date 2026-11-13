<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use RecordsActivity;

    protected $fillable = ['product_id', 'name', 'notes', 'yield_quantity'];

    protected function casts(): array
    {
        return ['yield_quantity' => 'decimal:4'];
    }

    /** Hasil per resep (minimal 0,0001): bahan di resep ini menghasilkan sekian satuan produk. */
    public function yieldQuantity(): float
    {
        return max((float) ($this->yield_quantity ?? 1), 0.0001);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('id');
    }

    protected function activityLogName(): string
    {
        return 'inventori';
    }

    protected function activityLogLabel(): string
    {
        return 'Resep';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return $this->getFillable();
    }
}
