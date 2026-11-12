<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthors;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anggaran satu tahun: untuk semua cabang (branch_id null) atau satu cabang, dan opsional satu
 * cost center. Tidak memakai BelongsToBranch karena anggaran tanpa cabang berlaku untuk semuanya.
 */
class Budget extends Model
{
    use SoftDeletes, TracksAuthors;

    protected $fillable = ['fiscal_year', 'name', 'branch_id', 'cost_center_id', 'notes'];

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $budget) => $budget->company_id ??= Company::query()->where('is_default', true)->value('id'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }

    /** Anggaran yang terlihat di cabang aktif: tanpa cabang, atau cabang yang boleh dilihat. */
    public function scopeVisible(Builder $query): Builder
    {
        $ids = app(CurrentBranch::class)->scopeIds();

        return $query->when($ids !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $ids ?: [0])));
    }

    /** "Semua cabang · Toko" */
    public function coverageLabel(): string
    {
        return collect([$this->branch?->name ?? 'Semua cabang', $this->costCenter?->label()])->filter()->join(' · ');
    }
}
