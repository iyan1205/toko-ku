<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Riwayat stok bersifat tambah-saja (append-only): tidak boleh diubah atau dihapus.
 * Koreksi dilakukan dengan mutasi baru, bukan dengan mengedit mutasi lama.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id', 'type', 'reference_type', 'reference_id',
        'quantity', 'stock_before', 'stock_after', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat stok tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat stok tidak boleh dihapus.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param array{produk?:?int,jenis?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['produk'] ?? null, fn (Builder $q, $v) => $q->where('product_id', $v))
            ->when($f['jenis'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v));
    }
}
