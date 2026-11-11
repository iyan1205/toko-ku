<?php

namespace App\Models;

use App\Enums\CashBankKind;
use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rekening kas & bank. Menempel pada satu akun kas/bank di bagan akun (account_id); saldonya
 * adalah saldo akun itu dari jurnal posted (App\Services\CashBank\CashBankAccountService).
 * branch_id kosong = dipakai semua cabang. Kas kecil (petty_cash) punya plafon imprest_amount.
 */
class CashBankAccount extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes, TracksAuthors;

    protected $fillable = ['kind', 'name', 'bank_name', 'account_number', 'account_holder', 'imprest_amount', 'is_active', 'note', 'branch_id'];

    protected function casts(): array
    {
        return [
            'kind' => CashBankKind::class,
            'imprest_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $rekening) => $rekening->company_id ??= Company::query()->where('is_default', true)->value('id'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** Rekening yang boleh dipakai di cabang aktif: milik cabang itu atau untuk semua cabang. */
    public function scopeAvailable(Builder $query): Builder
    {
        $ids = app(CurrentBranch::class)->scopeIds();

        return $query->when($ids !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $ids ?: [0])));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw("CASE kind WHEN 'cash' THEN 0 WHEN 'petty_cash' THEN 1 ELSE 2 END")->orderBy('name');
    }

    public function isPettyCash(): bool
    {
        return $this->kind === CashBankKind::PettyCash;
    }

    /** "BCA 1234567890 (Kas Toko)" untuk pilihan. */
    public function label(): string
    {
        return trim($this->name.($this->account_number ? " · {$this->account_number}" : ''));
    }

    protected function activityLogName(): string
    {
        return 'akuntansi';
    }

    protected function activityLogLabel(): string
    {
        return 'Rekening';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['kind', 'name', 'bank_name', 'account_number', 'account_holder', 'imprest_amount', 'is_active', 'branch_id'];
    }
}
