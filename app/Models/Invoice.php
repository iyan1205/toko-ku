<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentStatus;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Faktur manual non-stok: faktur penjualan (type sales, piutang pelanggan) atau tagihan
 * pembelian (type purchase, hutang supplier), misal jasa, sewa, atau biaya. kind = opening untuk
 * piutang/hutang dari Saldo Awal (jurnalnya sudah ada di jurnal saldo awal).
 *
 * Saldo: balance = total - paid_amount - credited_amount. Dibayar lewat PaymentService (baris
 * payments), dikurangi nota kredit/debit lewat Pelunasan. Dibuat dan dibatalkan lewat
 * App\Services\Receivables\InvoiceService.
 */
class Invoice extends Model
{
    use BelongsToBranch, HasAttachments, HasDocumentStatus, SoftDeletes, TracksAuthors;

    protected $fillable = ['type', 'customer_id', 'supplier_id', 'invoice_date', 'due_date', 'reference', 'description'];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Faktur posted tetap berkurang sisanya karena pembayaran dan nota. */
    public function columnsChangeableWhenLocked(): array
    {
        return ['paid_amount', 'credited_amount', 'balance', 'payment_status'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->orderBy('paid_at')->orderBy('id');
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(OpeningBalance::class)->withTrashed();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function isSales(): bool
    {
        return $this->type === InvoiceType::Sales;
    }

    public function isOpening(): bool
    {
        return $this->kind === 'opening';
    }

    /** "Saldo awal · INV-LAMA-7" / "Faktur penjualan · PO-123": jenis plus nomor referensi bila ada. */
    public function displayLabel(): string
    {
        return ($this->isOpening() ? 'Saldo awal' : $this->type->label()).($this->reference ? " · {$this->reference}" : '');
    }

    /** Pelanggan (faktur penjualan) atau supplier (tagihan). */
    public function party(): Customer|Supplier|null
    {
        return $this->isSales() ? $this->customer : $this->supplier;
    }

    /** Lampiran: izin faktur penjualan (pos.invoices.*) atau tagihan pembelian (purchasing.bills.*). */
    public function attachmentPermission(string $action): ?string
    {
        return $this->type->permission($action === 'view' ? 'view' : 'create');
    }

    public function scopeOfType(Builder $query, InvoiceType $type): Builder
    {
        return $query->where($this->qualifyColumn('type'), $type->value);
    }

    /** Faktur posted yang masih punya sisa. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), DocumentStatus::Posted->value)->where($this->qualifyColumn('balance'), '>', 0);
    }

    public function documentType(): DocumentType
    {
        return ($this->type instanceof InvoiceType ? $this->type : InvoiceType::from($this->type))->documentType();
    }

    public function documentDateColumn(): string
    {
        return 'invoice_date';
    }
}
