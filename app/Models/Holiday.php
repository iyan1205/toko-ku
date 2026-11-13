<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hari libur: nasional (semua cabang) atau khusus satu cabang. Dipakai WorkCalendar: tidak
 * dihitung alpa, tidak terlambat, lembur dengan tarif hari libur, dan cuti hari kerja.
 */
class Holiday extends Model
{
    use HasFactory, RecordsActivity, SoftDeletes, TracksAuthors;

    protected $fillable = ['date', 'name', 'is_national', 'branch_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_national' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** Libur yang berlaku untuk cabang ini (libur nasional + libur cabang). */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('branch_id')->when($branchId, fn (Builder $w) => $w->orWhere('branch_id', $branchId)));
    }

    protected function activityLogName(): string
    {
        return 'sdm';
    }

    protected function activityLogLabel(): string
    {
        return 'Hari libur';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['date', 'name', 'is_national', 'branch_id'];
    }
}
