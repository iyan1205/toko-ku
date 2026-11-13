<?php

namespace App\Models;

use App\Enums\StockTransferStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Scopes\BranchScope;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Transfer stok antar gudang. Gudang dalam satu cabang: selesai saat disimpan. Antar cabang: dikirim
 * (dalam perjalanan) lalu diterima gudang tujuan. Kesalahan dibatalkan (void) lewat StockTransferService,
 * tidak diedit atau dihapus. Terlihat oleh cabang gudang asal dan cabang gudang tujuan.
 */
class StockTransfer extends Model
{
    use BelongsToBranch;

    protected $fillable = ['number', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'status' => StockTransferStatus::class,
            'voided_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id')->withTrashed();
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function dibatalkan(): bool
    {
        return $this->status === StockTransferStatus::Voided;
    }

    /** Antar cabang = kedua gudang punya cabang dan cabangnya berbeda (gudang tanpa cabang dipakai bersama). */
    public static function isInterBranch(?Warehouse $from, ?Warehouse $to): bool
    {
        return $from?->branch_id !== null && $to?->branch_id !== null && (int) $from->branch_id !== (int) $to->branch_id;
    }

    public function inTransit(): bool
    {
        return $this->status === StockTransferStatus::InTransit;
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }

    /** Ada barang yang kurang saat diterima (hilang/rusak di perjalanan). */
    public function hasShortage(): bool
    {
        return $this->items->contains(fn (StockTransferItem $i) => $i->received_quantity !== null && (float) $i->received_quantity < (float) $i->quantity);
    }

    /**
     * Transfer cabang aktif: dari gudang cabang ini ATAU ke gudang cabang ini (penerima melihat kiriman masuk).
     */
    public function scopeVisible(Builder $query): Builder
    {
        $ids = app(CurrentBranch::class)->scopeIds();

        return $query->withoutGlobalScope(BranchScope::class)
            ->when($ids !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereIn($w->qualifyColumn('branch_id'), $ids)
                ->orWhereIn('to_warehouse_id', Warehouse::withTrashed()->whereIn('branch_id', $ids)->select('id'))));
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return static::query()->visible()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /** Diubah juga oleh cabang penerima (terima barang), jadi tidak dibatasi cabang aktif. */
    protected function guardsBranchAccess(): bool
    {
        return false;
    }

    /** @param array{cari?:?string,gudang?:?int,status?:?string,dari?:?string,sampai?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(function (Builder $w) use ($kata) {
                $w->where('number', 'like', "%{$kata}%")
                    ->orWhere('note', 'like', "%{$kata}%")
                    ->orWhereHas('items', fn (Builder $i) => $i->where('product_name', 'like', "%{$kata}%")->orWhere('sku', 'like', "%{$kata}%"));
            }))
            ->when($f['gudang'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w->where('from_warehouse_id', $id)->orWhere('to_warehouse_id', $id)))
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('transfer_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('transfer_date', '<=', $v));
    }

    /** Transfer milik cabang gudang asal. */
    protected function branchWarehouseColumn(): string
    {
        return 'from_warehouse_id';
    }
}
