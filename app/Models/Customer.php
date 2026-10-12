<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    public const KODE_UMUM = 'UMUM';

    /** `receivable_balance` tidak fillable: hanya diubah modul piutang (Tahap 7). */
    protected $fillable = ['code', 'name', 'phone', 'email', 'address', 'note', 'is_default'];

    protected function casts(): array
    {
        return [
            'receivable_balance' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    /** Pelanggan bawaan untuk transaksi tanpa pelanggan; dibuat bila belum ada. */
    public static function umum(): static
    {
        return static::withTrashed()->firstOrCreate(
            ['code' => self::KODE_UMUM],
            ['name' => 'Umum', 'is_default' => true],
        );
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function scopeCari(Builder $query, ?string $kata): Builder
    {
        if ($kata === null || trim($kata) === '') {
            return $query;
        }

        $kata = '%' . trim($kata) . '%';

        return $query->where(function (Builder $q) use ($kata) {
            $q->where('name', 'like', $kata)
                ->orWhere('code', 'like', $kata)
                ->orWhere('phone', 'like', $kata);
        });
    }
}
