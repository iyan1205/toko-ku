<?php

namespace App\Models;

use App\Enums\StatusStok;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * `stock` sengaja TIDAK ada di $fillable. Stok hanya boleh berubah lewat
     * StockService + stock_movements (Tahap 3), tidak lewat formulir atau mass assignment.
     */
    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'category_id',
        'unit_id',
        'purchase_price',
        'selling_price',
        'min_stock',
        'discount_percent',
        'photo',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function statusStok(): StatusStok
    {
        $stok = (float) $this->stock;

        return match (true) {
            $stok <= 0 => StatusStok::Habis,
            $stok <= (float) $this->min_stock => StatusStok::Menipis,
            default => StatusStok::Aman,
        };
    }

    public function photoUrl(): ?string
    {
        return $this->photo ? Storage::disk('public')->url($this->photo) : null;
    }

    /** Harga jual setelah diskon produk (hanya untuk tampilan; perhitungan transaksi ada di Tahap 5). */
    public function hargaSetelahDiskon(): float
    {
        return round((float) $this->selling_price * (1 - (float) $this->discount_percent / 100), 2);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeStokHabis(Builder $query): Builder
    {
        return $query->where('stock', '<=', 0);
    }

    public function scopeStokMenipis(Builder $query): Builder
    {
        return $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'min_stock');
    }

    public function scopeStokAman(Builder $query): Builder
    {
        return $query->whereColumn('stock', '>', 'min_stock');
    }

    public function scopeCari(Builder $query, ?string $kata): Builder
    {
        if ($kata === null || trim($kata) === '') {
            return $query;
        }

        $kata = trim($kata);

        return $query->where(function (Builder $q) use ($kata) {
            $q->where('name', 'like', "%{$kata}%")
                ->orWhere('sku', 'like', "%{$kata}%")
                ->orWhere('barcode', $kata);
        });
    }

    /** @param array{cari?:?string,kategori?:?int,stok?:?string,status?:?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->cari($f['cari'] ?? null)
            ->when($f['kategori'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($f['stok'] ?? null, fn (Builder $q, $v) => match ($v) {
                'habis' => $q->stokHabis(),
                'menipis' => $q->stokMenipis(),
                'aman' => $q->stokAman(),
                default => $q,
            })
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('is_active', $v === 'aktif'));
    }
}
