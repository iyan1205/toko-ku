<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Penjualan tidak diedit dan tidak dihapus. Dibatalkan (void) oleh yang berhak, atau diretur.
 * Kolom pembayaran/status/retur hanya diubah oleh SaleService, PaymentService, dan
 * SaleReturnService (tidak fillable).
 *
 * grand_total = subtotal - item_discount - discount (+ tax bila pajak belum termasuk harga).
 * Bila tax_inclusive, tax adalah bagian pajak yang sudah ada di dalam grand_total.
 */
class Sale extends Model
{
    protected $fillable = [
        'number', 'warehouse_id', 'cash_session_id', 'customer_id', 'user_id', 'sold_at', 'subtotal', 'item_discount', 'discount',
        'tax_rate', 'tax_inclusive', 'tax', 'grand_total', 'tendered_amount', 'change_amount', 'payment_method', 'note',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'tax' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'returned_amount' => 'decimal:2',
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
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

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('id');
    }

    /** Nama dan tarif pajak untuk struk dan detail, misal "PPN 11%". Nama diambil dari Pengaturan → Pajak. */
    public function taxLabel(): string
    {
        return trim(setting('tax.name', 'PPN').' '.Format::jumlah($this->tax_rate).'%');
    }

    /**
     * Pembayaran yang dirinci di struk: hanya untuk penjualan campuran (lebih dari satu metode).
     *
     * @return Collection<int, Payment>
     */
    public function receiptPayments(): Collection
    {
        if ($this->payment_method !== PaymentMethod::Mixed || ! $this->exists) {
            return new Collection;
        }

        return $this->payments->reject(fn (Payment $p) => $p->dibatalkan())->values();
    }

    public function dibatalkan(): bool
    {
        return $this->status === PurchaseStatus::Voided;
    }

    /** Penjualan selesai yang masih punya sisa piutang. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->selesai()->where('balance', '>', 0);
    }

    public function scopeSelesai(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::Completed->value);
    }

    /** @param array{cari?:?string,kasir?:?int,pelanggan?:?int,metode?:?string,status?:?string,sesi?:?int,gudang?:?int,dari?:?string,sampai?:?string} $f */
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
            ->when($f['sesi'] ?? null, fn (Builder $q, $v) => $q->where('cash_session_id', $v))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('sold_at', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('sold_at', '<=', $v));
    }
}
