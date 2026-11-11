<?php

namespace App\Models;

use App\Enums\OpeningBalanceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris saldo awal. account: akun + debit/kredit; inventory: gudang + nilai (debit);
 * receivable: pelanggan + nilai (debit); payable: supplier + nilai (kredit).
 */
class OpeningBalanceLine extends Model
{
    protected $fillable = ['kind', 'account_id', 'warehouse_id', 'customer_id', 'supplier_id', 'reference', 'due_date', 'description', 'debit', 'credit'];

    protected function casts(): array
    {
        return [
            'kind' => OpeningBalanceKind::class,
            'due_date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** Nilai baris (sisi yang terisi). */
    public function amount(): string
    {
        return (float) $this->debit > 0 ? (string) $this->debit : (string) $this->credit;
    }
}
