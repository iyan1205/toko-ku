<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu lapisan stok di satu gudang (dibuat oleh setiap stok masuk): nomor batch, tanggal kedaluwarsa,
 * biaya per unit, dan sisa. Hanya diubah oleh StockService. Urutan pakai: kedaluwarsa terdekat dulu
 * (FEFO), lalu yang masuk lebih dulu (FIFO).
 */
class StockLot extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date', 'received_at' => 'datetime', 'unit_cost' => 'decimal:2',
            'quantity_in' => 'decimal:3', 'quantity' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** Urutan keluar: FEFO lalu FIFO. */
    public function scopeConsumeOrder(Builder $query): Builder
    {
        return $query->orderByRaw('case when expiry_date is null then 1 else 0 end')
            ->orderBy('expiry_date')->orderBy('received_at')->orderBy('id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(today());
    }

    /** "B2301 · exp 08 Des 2026" */
    public function label(): string
    {
        return collect([$this->batch_number, $this->expiry_date ? 'exp '.$this->expiry_date->translatedFormat('d M Y') : null])->filter()->join(' · ') ?: 'Tanpa batch';
    }
}
