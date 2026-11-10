<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gudang/lokasi stok. Tepat satu gudang berstatus utama (is_default): dipakai kasir
 * dan semua transaksi yang tidak memilih gudang. Dikelola di Inventori → Gudang/Lokasi.
 */
class Warehouse extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes;

    protected $fillable = ['code', 'name', 'address', 'branch_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Gudang utama. Selalu ada (dibuat oleh migration create_warehouses_table). */
    public static function default(): self
    {
        return static::query()->where('is_default', true)->firstOrFail();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Gudang utama dulu, lalu urut nama. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('name');
    }

    /** Jumlah seluruh stok di gudang ini (semua produk). */
    public function totalStock(): float
    {
        return round((float) $this->stocks()->sum('quantity'), 3);
    }

    protected function activityLogName(): string
    {
        return 'inventori';
    }

    protected function activityLogLabel(): string
    {
        return 'Gudang';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'address', 'branch_id', 'is_active', 'is_default'];
    }
}
