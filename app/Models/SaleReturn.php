<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SaleReturnStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Retur penjualan. Dibuat, disetujui, dan dihapus hanya lewat SaleReturnService.
 * Status, nilai, dan kolom persetujuan tidak fillable.
 */
class SaleReturn extends Model
{
    protected $fillable = ['number', 'sale_id', 'customer_id', 'warehouse_id', 'user_id', 'return_date', 'reason'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => SaleReturnStatus::class,
            'total' => 'decimal:2',
            'receivable_reduction' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'refund_method' => PaymentMethod::class,
            'approved_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
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
        return $this->hasMany(SaleReturnItem::class)->orderBy('id');
    }

    /** Uang yang dikembalikan ke pelanggan (satu baris, dicatat saat disetujui). */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->orderBy('id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isDraft(): bool
    {
        return $this->status === SaleReturnStatus::Draft;
    }

    /** @param array{cari?:?string,pelanggan?:?int,kasir?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhere('reason', 'like', "%{$kata}%")
                    ->orWhereHas('sale', fn (Builder $s) => $s->where('number', 'like', "%{$kata}%"))
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['pelanggan'] ?? null, fn (Builder $q, $v) => $q->where('customer_id', $v))
            ->when($f['kasir'] ?? null, fn (Builder $q, $v) => $q->where('user_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('return_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('return_date', '<=', $v));
    }
}
