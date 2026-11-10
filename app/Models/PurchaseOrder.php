<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order ke supplier. Hanya draft yang bisa diubah atau dihapus. Setelah disetujui,
 * barang diterima lewat Penerimaan Barang (boleh sebagian), dan setiap penerimaan membuat
 * faktur/hutang sendiri. Status dan kolom persetujuan hanya diubah PurchaseOrderService.
 *
 * grand_total = subtotal - item_discount - discount + tax + other_cost (subtotal = sebelum diskon baris).
 */
class PurchaseOrder extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'number', 'supplier_id', 'warehouse_id', 'order_date', 'expected_date',
        'subtotal', 'item_discount', 'discount', 'tax', 'other_cost', 'grand_total', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'status' => PurchaseOrderStatus::class,
            'subtotal' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class)->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseOrderStatus::Draft;
    }

    /** Ada penerimaan yang belum dibatalkan. */
    public function hasActiveReceipts(): bool
    {
        return $this->receipts()->where('status', PurchaseStatus::Completed->value)->exists();
    }

    /** @param array{cari?:?string,supplier?:?int,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['supplier'] ?? null, fn (Builder $q, $v) => $q->where('supplier_id', $v))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('order_date', '<=', $v));
    }
}
