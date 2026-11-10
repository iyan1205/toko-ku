<?php

namespace App\Models;

use App\Enums\CashSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesi kasir. Dibuka, ditutup, dan diverifikasi hanya lewat CashSessionService
 * (status, angka penutupan, dan verifikasi tidak fillable).
 */
class CashSession extends Model
{
    protected $fillable = ['number', 'user_id', 'warehouse_id', 'branch_id', 'opened_at', 'opening_cash', 'opening_note'];

    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'verified_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'closing_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'summary' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by')->withTrashed();
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CashSessionStatus::Open->value);
    }

    /** @param array{cari?:?string,kasir?:?int,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$kata}%"));
            }))
            ->when($f['kasir'] ?? null, fn (Builder $q, $v) => $q->where('user_id', $v))
            ->when($f['gudang'] ?? null, fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => match ($v) {
                'verified' => $q->whereNotNull('verified_at'),
                'unverified' => $q->where('status', CashSessionStatus::Closed->value)->whereNull('verified_at'),
                default => $q->where('status', $v),
            })
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('opened_at', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('opened_at', '<=', $v));
    }
}
