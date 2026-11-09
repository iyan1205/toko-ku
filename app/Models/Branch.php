<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cabang/outlet toko. Tepat satu cabang berstatus utama (is_default); cabang utama
 * selalu aktif dan tidak bisa dihapus. Dikelola di Pengaturan → Cabang.
 */
class Branch extends Model
{
    use RecordsActivity, SoftDeletes;

    protected $fillable = ['code', 'name', 'address', 'phone', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Cabang utama dulu, lalu urut nama. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('name');
    }

    protected function activityLogName(): string
    {
        return 'pengaturan';
    }

    protected function activityLogLabel(): string
    {
        return 'Cabang';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'address', 'phone', 'is_active', 'is_default'];
    }
}
