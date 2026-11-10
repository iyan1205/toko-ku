<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Penerimaan barang dari purchase order. Menambah stok gudang tujuan dan membuat satu faktur
 * (purchase_id) berisi hutang senilai barang yang diterima. Dibatalkan (void) lewat
 * GoodsReceiptService, tidak diedit atau dihapus. Status memakai PurchaseStatus.
 */
class GoodsReceipt extends Model
{
    use BelongsToBranch, HasAttachments;

    protected $fillable = [
        'number', 'purchase_order_id', 'supplier_id', 'warehouse_id', 'received_date',
        'delivery_note_number', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'status' => PurchaseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function dibatalkan(): bool
    {
        return $this->status === PurchaseStatus::Voided;
    }

    /** @param array{cari?:?string,supplier?:?int,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhere('delivery_note_number', 'like', "%{$kata}%")
                    ->orWhereHas('purchaseOrder', fn (Builder $p) => $p->where('number', 'like', "%{$kata}%"))
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['supplier'] ?? null, fn (Builder $q, $v) => $q->where('supplier_id', $v))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('received_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('received_date', '<=', $v));
    }
}
