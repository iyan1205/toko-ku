<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Kategori aset tetap: umur ekonomis, metode penyusutan, dan akun-akunnya. Disalin ke aset saat dicatat. */
class AssetCategory extends Model
{
    use RecordsActivity, SoftDeletes, TracksAuthors;

    protected $fillable = ['code', 'name', 'useful_life_months', 'method', 'declining_rate', 'asset_account_id', 'accumulated_account_id', 'expense_account_id', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'useful_life_months' => 'integer',
            'method' => DepreciationMethod::class,
            'declining_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $category) => $category->company_id ??= Company::query()->where('is_default', true)->value('id'));
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id')->withTrashed();
    }

    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id')->withTrashed();
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id')->withTrashed();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "4 tahun · garis lurus" */
    public function ruleLabel(): string
    {
        $tahun = $this->useful_life_months % 12 === 0 ? ($this->useful_life_months / 12).' tahun' : $this->useful_life_months.' bulan';

        return $tahun.' · '.mb_strtolower($this->method->label()).($this->method === DepreciationMethod::DecliningBalance ? ' '.(float) $this->declining_rate.'%/tahun' : '');
    }

    protected function activityLogName(): string
    {
        return 'akuntansi';
    }

    protected function activityLogLabel(): string
    {
        return 'Kategori aset';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['code', 'name', 'useful_life_months', 'method', 'declining_rate', 'asset_account_id', 'accumulated_account_id', 'expense_account_id', 'is_active'];
    }
}
