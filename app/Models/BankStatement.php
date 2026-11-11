<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rekening koran yang diimpor untuk rekonsiliasi satu rekening pada satu periode. Status open
 * (masih dicocokkan) atau finalized (dikunci; pencocokan tidak bisa diubah lagi).
 * Lihat App\Services\CashBank\BankReconciliationService.
 */
class BankStatement extends Model
{
    use BelongsToBranch, SoftDeletes, TracksAuthors;

    public const OPEN = 'open';

    public const FINALIZED = 'finalized';

    protected $fillable = ['cash_bank_account_id', 'period_start', 'period_end', 'opening_balance', 'closing_balance', 'file_name'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('line_date')->orderBy('id');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class)->withTrashed();
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by')->withTrashed();
    }

    public function isFinalized(): bool
    {
        return $this->status === self::FINALIZED;
    }

    protected function branchSource(): ?array
    {
        $branchId = CashBankAccount::withTrashed()->whereKey($this->cash_bank_account_id)->value('branch_id');

        return $branchId ? ['cash_bank_account', $this->cash_bank_account_id] : null;
    }

    /** Rekening untuk semua cabang tidak dibatasi cabang aktif saat diimpor. */
    protected function guardsBranchAccess(): bool
    {
        return false;
    }
}
