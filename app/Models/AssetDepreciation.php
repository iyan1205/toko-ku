<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penyusutan satu aset untuk satu periode. */
class AssetDepreciation extends Model
{
    protected $fillable = ['fixed_asset_id', 'period', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id')->withTrashed();
    }
}
