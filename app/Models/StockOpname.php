<?php

namespace App\Models;

use App\Enums\StockOpnameStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Stok opname per gudang. Draft menyimpan hitungan fisik tanpa mengubah stok; saat
 * diselesaikan (final), selisihnya dibukukan lewat StockOpnameService. Status dan kolom
 * finalized_* hanya diubah service itu (tidak fillable).
 */
class StockOpname extends Model
{
    protected $fillable = ['number', 'warehouse_id', 'opname_date', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'opname_date' => 'date',
            'status' => StockOpnameStatus::class,
            'finalized_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by')->withTrashed();
    }

    /** Mutasi stok yang dihasilkan opname ini (hanya produk yang selisihnya tidak nol). */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isDraft(): bool
    {
        return $this->status === StockOpnameStatus::Draft;
    }

    /** @param array{cari?:?string,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$kata}%")->orWhere('note', 'like', "%{$kata}%")))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('opname_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('opname_date', '<=', $v));
    }
}
