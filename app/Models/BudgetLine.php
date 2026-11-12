<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nilai anggaran satu akun untuk satu bulan (1–12). */
class BudgetLine extends Model
{
    protected $fillable = ['account_id', 'month', 'amount'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'amount' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
