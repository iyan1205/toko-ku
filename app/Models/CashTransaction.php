<?php

namespace App\Models;

use App\Enums\CashTransactionType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasApproval;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentStatus;
use App\Models\Concerns\TracksAuthors;
use App\Services\CashBank\CashTransactionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penerimaan kas, pengeluaran kas, atau transfer antar rekening. Dibuat lewat
 * App\Services\CashBank\CashTransactionService. Pengeluaran dan transfer bisa melewati alur
 * persetujuan (dokumen cash_out / cash_transfer); setelah disetujui langsung diposting.
 * Saat diposting dibuat satu jurnal (sumber cash_transaction); pembatalan membuat jurnal pembalik.
 */
class CashTransaction extends Model
{
    use BelongsToBranch, HasApproval, HasAttachments, HasDocumentStatus, SoftDeletes, TracksAuthors;

    protected $fillable = ['type', 'transaction_date', 'cash_bank_account_id', 'target_cash_bank_account_id', 'counterparty', 'reference', 'description', 'amount', 'fee', 'fee_account_id'];

    protected function casts(): array
    {
        return [
            'type' => CashTransactionType::class,
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashTransactionLine::class)->orderBy('id');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class)->withTrashed();
    }

    public function targetCashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'target_cash_bank_account_id')->withTrashed();
    }

    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fee_account_id')->withTrashed();
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    /** Transaksi ikut cabang rekeningnya bila rekening itu khusus satu cabang. */
    protected function branchSource(): ?array
    {
        $branchId = CashBankAccount::withTrashed()->whereKey($this->cash_bank_account_id)->value('branch_id');

        return $branchId ? ['cash_bank_account', $this->cash_bank_account_id] : null;
    }

    public function documentType(): DocumentType
    {
        return ($this->type instanceof CashTransactionType ? $this->type : CashTransactionType::from($this->type))->documentType();
    }

    public function documentDateColumn(): string
    {
        return 'transaction_date';
    }

    public function approvalDocumentType(): string
    {
        return $this->type->approvalDocument();
    }

    public function approvalTitle(): string
    {
        return "{$this->number} · {$this->description}";
    }

    public function approvalUrl(): string
    {
        return route('cash-transactions.show', $this);
    }

    public function approvalAmount(): string
    {
        return (string) ((float) $this->amount + (float) $this->fee);
    }

    /** Disetujui semua tahap: langsung diposting atas nama penyetuju terakhir. */
    public function onApprovalApproved(ApprovalRequest $request, User $by): void
    {
        $this->moveToStatus(DocumentStatus::Approved, $by);
        app(CashTransactionService::class)->post($this, $by);
    }
}
