<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Faktur pembelian = catatan hutang ke supplier. Dibuat langsung (pembelian tanpa PO,
 * stok masuk saat itu juga) atau otomatis dari Penerimaan Barang (stok masuk lewat
 * penerimaan). Tidak diedit dan tidak dihapus: kesalahan dibatalkan (void), barang yang
 * dikembalikan lewat Retur Pembelian. Kolom pembayaran, retur, dan status hanya diubah
 * oleh PaymentService/PurchaseService/PurchaseReturnService (tidak fillable).
 *
 * grand_total = subtotal - item_discount - discount + tax + other_cost (subtotal = sebelum diskon baris).
 */
class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'warehouse_id', 'supplier_id', 'purchase_order_id', 'supplier_invoice_number', 'purchase_date', 'due_date',
        'subtotal', 'item_discount', 'discount', 'tax', 'other_cost', 'grand_total', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Penerimaan barang yang membuat faktur ini (kosong untuk pembelian langsung). */
    public function goodsReceipt(): HasOne
    {
        return $this->hasOne(GoodsReceipt::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class)->orderBy('id');
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

    /** Faktur yang masih punya sisa hutang. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->selesai()->where('balance', '>', 0);
    }

    /** Barang faktur ini masuk lewat Penerimaan Barang (bukan pembelian langsung). */
    public function fromReceipt(): bool
    {
        return $this->purchase_order_id !== null;
    }

    /** @param array{cari?:?string,supplier?:?int,gudang?:?int,pembayaran?:?string,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhere('supplier_invoice_number', 'like', "%{$kata}%")
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['supplier'] ?? null, fn (Builder $q, $v) => $q->where('supplier_id', $v))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['pembayaran'] ?? null, fn (Builder $q, $v) => $q->where('payment_status', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('purchase_date', '<=', $v));
    }
}
