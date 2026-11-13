<?php

namespace App\Models;

use App\Enums\StatusStok;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes;

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
        'track_batches',
        'track_serials',
        'is_bundle',
        'type',
        'is_purchasable',
        'parent_id',
        'variant_options',
        'variant_values',
        'discount_percent',
        'photo',
        'description',
        'is_active',
    ];

    /** Nilai bawaan kolom baru, supaya model baru (sebelum dimuat ulang) tidak menganggapnya berubah. */
    protected $attributes = ['track_batches' => false, 'track_serials' => false, 'is_bundle' => false, 'type' => 'jual', 'is_purchasable' => true];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'track_batches' => 'boolean',
            'track_serials' => 'boolean',
            'is_bundle' => 'boolean',
            'is_purchasable' => 'boolean',
            'variant_options' => 'array',
            'variant_values' => 'array',
        ];
    }

    /** Satu produk jual punya satu BOM aktif. */
    public function recipe(): HasOne
    {
        return $this->hasOne(Recipe::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Stok per gudang. products.stock = jumlah seluruh baris ini. */
    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    /** Stok di satu gudang (0 bila belum pernah ada mutasi di gudang itu). */
    public function stockIn(Warehouse|int $warehouse): float
    {
        $id = $warehouse instanceof Warehouse ? $warehouse->getKey() : $warehouse;

        return round((float) ($this->relationLoaded('warehouseStocks')
            ? $this->warehouseStocks->firstWhere('warehouse_id', $id)?->quantity
            : $this->warehouseStocks()->where('warehouse_id', $id)->value('quantity')), 3);
    }

    /** Satuan lain dengan konversi (DUS, PAK, ...). Satuan dasar = unit_id. */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('factor');
    }

    /** Produk induk (untuk varian). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id')->withTrashed();
    }

    /** Varian produk induk ini (masing-masing produk sendiri dengan SKU/stok/harga). */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id')->orderBy('name');
    }

    /** Produk induk varian: hanya kerangka, tidak dijual/distok sendiri. */
    public function isVariantParent(): bool
    {
        return ! empty($this->variant_options);
    }

    /** "M / Merah" */
    public function variantLabel(): ?string
    {
        return empty($this->variant_values) ? null : implode(' / ', array_values($this->variant_values));
    }

    /**
     * Komponen paket (dari resep/BOM): [produk, jumlah per satu paket].
     *
     * @return Collection<int, array{product: Product, quantity: float}>
     */
    public function bundleComponents(): Collection
    {
        if (! $this->is_bundle) {
            return collect();
        }
        $this->loadMissing('recipe.items.product');

        return ($this->recipe?->items ?? collect())->filter(fn ($i) => $i->product !== null && (float) $i->quantity > 0)
            ->map(fn ($i) => ['product' => $i->product, 'quantity' => (float) $i->quantity / max((float) $this->recipe->yield_quantity, 0.0001)])->values();
    }

    /** Dijual di kasir (type jual). Bahan baku seperti karkas hanya dibeli/diolah. */
    public function isSellable(): bool
    {
        return ($this->type ?? 'jual') === 'jual';
    }

    /** Produk olahan: punya resep berbahan dan bukan paket. Menambah stoknya (produksi) memakai bahan resep. */
    public function isProduced(): bool
    {
        return ! $this->is_bundle && $this->recipe !== null && $this->recipe->items->isNotEmpty();
    }

    /** Pemecahan bahan ini (misal Karkas 1 → potongan ayam). */
    public function breakdown(): HasOne
    {
        return $this->hasOne(ProductBreakdown::class, 'source_product_id');
    }

    /** Pemecahan aktif yang menghasilkan produk ini (misal dada dari Karkas 1), atau null. */
    public function breakdownSource(): ?ProductBreakdown
    {
        return ProductBreakdown::query()->where('is_active', true)
            ->whereHas('outputs', fn (Builder $q) => $q->where('product_id', $this->getKey()))
            ->with(['source.unit', 'outputs.product.unit'])->first();
    }

    /** Bisa dibeli dari supplier (tampil di PO dan pembelian). */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_purchasable', true);
    }

    /** Produk yang punya stok sendiri: bukan induk varian dan bukan paket. */
    public function scopeStockable(Builder $query): Builder
    {
        return $query->whereNull('variant_options')->where('is_bundle', false);
    }

    /** Produk yang bisa dijual di kasir (paket boleh, induk varian tidak). */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->whereNull('variant_options');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
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

    /** Produk jual (tampil di kasir & daftar produk). Bahan baku tidak tampil. */
    public function scopeJual(Builder $query): Builder
    {
        return $query->where('type', 'jual');
    }

    /** Bahan baku / operasional (untuk BOM, tidak tampil di kasir). */
    public function scopeBahanBaku(Builder $query): Builder
    {
        return $query->where('type', 'bahan_baku');
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

    /** Produk aktif yang perlu diisi ulang: stok menipis atau habis (stok <= stok minimum). */
    public function scopeNeedsRestock(Builder $query): Builder
    {
        return $query->aktif()->stockable()->whereColumn('stock', '<=', 'min_stock');
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
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('is_active', $v === 'aktif'))
            ->when($f['jenis'] ?? null, fn (Builder $q, $v) => match ($v) {
                'olahan' => $q->where('is_purchasable', false),
                default => $q->where('type', $v),
            });
    }

    protected function activityLogName(): string
    {
        return 'inventori';
    }

    protected function activityLogLabel(): string
    {
        return 'Produk';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return $this->getFillable();
    }
}
