<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Pembelian tidak diedit dan tidak dihapus. Kesalahan dibatalkan (void) lalu dicatat ulang,
 * barang yang dikembalikan lewat Retur Pembelian. Kolom pembayaran dan status hanya
 * diubah oleh PaymentService/PurchaseService (tidak fillable).
 */
class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'supplier_id', 'supplier_invoice_number', 'purchase_date', 'due_date',
        'subtotal', 'discount', 'tax', 'other_cost', 'grand_total', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'status' => PurchaseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
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
        return $this->hasMany(PurchaseItem::class)->orderBy('id');
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

    /** @param array{cari?:?string,supplier?:?int,pembayaran?:?string,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhere('supplier_invoice_number', 'like', "%{$kata}%")
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['supplier'] ?? null, fn (Builder $q, $v) => $q->where('supplier_id', $v))
            ->when($f['pembayaran'] ?? null, fn (Builder $q, $v) => $q->where('payment_status', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('purchase_date', '<=', $v));
    }
}
