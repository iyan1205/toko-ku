<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris mutasi rekening koran. amount positif = uang masuk, negatif = uang keluar. */
class BankStatementLine extends Model
{
    protected $fillable = ['line_date', 'description', 'reference', 'amount', 'balance'];

    protected function casts(): array
    {
        return [
            'line_date' => 'date',
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by')->withTrashed();
    }

    public function isMatched(): bool
    {
        return $this->journal_line_id !== null;
    }

    public function isIncoming(): bool
    {
        return (float) $this->amount > 0;
    }
}
