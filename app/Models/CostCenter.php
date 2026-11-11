<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cost center / departemen: dimensi laporan opsional di baris jurnal. Boleh dihubungkan ke
 * departemen SDM. Cost center yang sudah dipakai jurnal tidak dihapus, cukup dinonaktifkan.
 */
class CostCenter extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes, TracksAuthors;

    protected $fillable = ['code', 'name', 'department_id', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $costCenter) => $costCenter->company_id ??= Company::query()->where('is_default', true)->value('id'));
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('code');
    }

    /** "CC-TOKO Toko" */
    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }

    protected function activityLogName(): string
    {
        return 'akuntansi';
    }

    protected function activityLogLabel(): string
    {
        return 'Cost center';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'department_id', 'description', 'is_active'];
    }
}
