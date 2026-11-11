<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\GiroStatus;
use App\Enums\SettlementType;
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
 * Pelunasan piutang/hutang (lihat App\Enums\SettlementType). Dibuat langsung posted lewat
 * App\Services\Receivables\SettlementService; koreksi dengan pembatalan (jurnal pembalik,
 * pembayaran dibatalkan, sisa faktur kembali).
 *
 *   amount          : uang yang diterima/dibayar (receipt/payment) atau nilai nota
 *   deposit_applied : uang muka pihak ini yang dipakai untuk melunasi
 *   deposit_added   : kelebihan yang disimpan sebagai uang muka (tanpa faktur = uang muka murni)
 *   Σ alokasi       = amount + deposit_applied - deposit_added
 */
class Settlement extends Model
{
    use BelongsToBranch, HasAttachments, HasDocumentStatus, SoftDeletes, TracksAuthors;

    protected $fillable = ['type', 'customer_id', 'supplier_id', 'settlement_date', 'method', 'cash_bank_account_id', 'counter_account_id', 'giro_number', 'giro_bank', 'giro_due_date', 'reference', 'description'];

    protected function casts(): array
    {
        return [
            'type' => SettlementType::class,
            'settlement_date' => 'date',
            'amount' => 'decimal:2',
            'deposit_applied' => 'decimal:2',
            'deposit_added' => 'decimal:2',
            'giro_due_date' => 'date',
            'giro_status' => GiroStatus::class,
            'giro_cleared_on' => 'date',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Status giro tetap berubah (cair/ditolak) setelah pelunasan diposting. */
    public function columnsChangeableWhenLocked(): array
    {
        return ['giro_status', 'giro_cleared_on', 'giro_cash_bank_account_id'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SettlementAllocation::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class)->withTrashed();
    }

    public function giroCashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'giro_cash_bank_account_id')->withTrashed();
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id')->withTrashed();
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function party(): Customer|Supplier|null
    {
        return $this->type->isReceivable() ? $this->customer : $this->supplier;
    }

    public function isGiro(): bool
    {
        return $this->method === 'giro';
    }

    public function allocatedTotal(): string
    {
        return (string) $this->allocations->sum(fn (SettlementAllocation $a) => (float) $a->amount);
    }

    /** Lampiran: izin piutang (pos.receivables.*) atau hutang (purchasing.payables.*). */
    public function attachmentPermission(string $action): ?string
    {
        return $this->type->permission($action === 'view' ? 'view' : 'create');
    }

    public function scopeOfTypes(Builder $query, SettlementType ...$types): Builder
    {
        return $query->whereIn($this->qualifyColumn('type'), array_map(fn (SettlementType $t) => $t->value, $types));
    }

    /** Ikut cabang rekeningnya bila rekening itu khusus satu cabang. */
    protected function branchSource(): ?array
    {
        $branchId = $this->cash_bank_account_id ? CashBankAccount::withTrashed()->whereKey($this->cash_bank_account_id)->value('branch_id') : null;

        return $branchId ? ['cash_bank_account', $this->cash_bank_account_id] : null;
    }

    public function documentType(): DocumentType
    {
        return ($this->type instanceof SettlementType ? $this->type : SettlementType::from($this->type))->documentType();
    }

    public function documentDateColumn(): string
    {
        return 'settlement_date';
    }
}
