<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Bagian pelunasan untuk satu dokumen (penjualan, faktur pembelian, atau faktur manual). */
class SettlementAllocation extends Model
{
    protected $fillable = ['document_type', 'document_id', 'amount', 'payment_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class)->withTrashed();
    }

    public function document(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
