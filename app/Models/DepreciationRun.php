<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Satu kali hitung penyusutan untuk satu periode (YYYY-MM) dan satu cabang; dijurnal sekali. */
class DepreciationRun extends Model
{
    use BelongsToBranch, SoftDeletes, TracksAuthors;

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['period'];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'asset_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('fixed_asset_id');
    }

    public function journals(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /** Nomor tampilan, misal "PNY 2026-11". */
    public function getNumberAttribute(): string
    {
        return "Penyusutan {$this->period}";
    }

    /** Run penyusutan dibuat sistem atas aset yang sudah lolos pemeriksaan cabang. */
    protected function guardsBranchAccess(): bool
    {
        return false;
    }
}
