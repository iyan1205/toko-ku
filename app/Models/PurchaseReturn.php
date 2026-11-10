<?php

namespace App\Models;

use App\Enums\PurchaseReturnStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Retur pembelian atas satu faktur. Draft bisa diubah atau dihapus; setelah disetujui
 * (PurchaseReturnService) stok keluar dan hutang faktur berkurang, dan dokumen terkunci.
 * Status, total, refund_amount, dan kolom persetujuan tidak fillable.
 */
class PurchaseReturn extends Model
{
    protected $fillable = ['number', 'purchase_id', 'supplier_id', 'warehouse_id', 'return_date', 'reason', 'user_id'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => PurchaseReturnStatus::class,
            'total' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class)->orderBy('id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseReturnStatus::Draft;
    }

    /** @param array{cari?:?string,supplier?:?int,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhere('reason', 'like', "%{$kata}%")
                    ->orWhereHas('purchase', fn (Builder $p) => $p->where('number', 'like', "%{$kata}%"))
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['supplier'] ?? null, fn (Builder $q, $v) => $q->where('supplier_id', $v))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('return_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('return_date', '<=', $v));
    }
}
