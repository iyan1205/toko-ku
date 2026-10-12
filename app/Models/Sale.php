<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Penjualan tidak diedit dan tidak dihapus. Dibatalkan (void) oleh yang berhak, atau diretur.
 * Kolom pembayaran/status hanya diubah oleh SaleService dan PaymentService (tidak fillable).
 */
class Sale extends Model
{
    protected $fillable = [
        'number', 'customer_id', 'user_id', 'sold_at', 'subtotal', 'item_discount', 'discount',
        'grand_total', 'tendered_amount', 'change_amount', 'payment_method', 'note',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'discount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'initial_paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'tendered_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'status' => PurchaseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
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
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->orderBy('id');
    }

    public function dibatalkan(): bool
    {
        return $this->status === PurchaseStatus::Voided;
    }

    public function scopeSelesai(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::Completed->value);
    }

    /** @param array{cari?:?string,kasir?:?int,pelanggan?:?int,metode?:?string,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['kasir'] ?? null, fn (Builder $q, $v) => $q->where('user_id', $v))
            ->when($f['pelanggan'] ?? null, fn (Builder $q, $v) => $q->where('customer_id', $v))
            ->when($f['metode'] ?? null, fn (Builder $q, $v) => $q->where('payment_method', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('sold_at', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('sold_at', '<=', $v));
    }
}
