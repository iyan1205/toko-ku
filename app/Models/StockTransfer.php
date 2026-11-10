<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Transfer stok antar gudang. Selesai saat disimpan; kesalahan dibatalkan (void) lewat
 * StockTransferService, tidak diedit atau dihapus. Status memakai PurchaseStatus
 * (completed/voided) seperti penjualan dan pembelian.
 */
class StockTransfer extends Model
{
    use BelongsToBranch;

    protected $fillable = ['number', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'status' => PurchaseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id')->withTrashed();
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function dibatalkan(): bool
    {
        return $this->status === PurchaseStatus::Voided;
    }

    /** @param array{cari?:?string,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhere('note', 'like', "%{$kata}%")
                    ->orWhereHas('items', fn (Builder $i) => $i->where('product_name', 'like', "%{$kata}%")->orWhere('sku', 'like', "%{$kata}%"));
            }))
            ->when($f['gudang'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w->where('from_warehouse_id', $id)->orWhere('to_warehouse_id', $id)))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('transfer_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('transfer_date', '<=', $v));
    }

    /** Transfer milik cabang gudang asal. */
    protected function branchWarehouseColumn(): string
    {
        return 'from_warehouse_id';
    }
}
