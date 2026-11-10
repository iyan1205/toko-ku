<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perusahaan pemilik cabang. Saat ini selalu tepat satu (is_default), dibuat oleh migration
 * create_companies_table; namanya mengikuti nama toko di Pengaturan → Profil Toko.
 */
class Company extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public static function default(): self
    {
        return static::query()->where('is_default', true)->firstOrFail();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
