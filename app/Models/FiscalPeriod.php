<?php

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kunci periode bulanan. Hanya diubah lewat FiscalPeriodService (tutup/buka kembali, tercatat di log).
 */
class FiscalPeriod extends Model
{
    protected $fillable = ['company_id', 'year', 'month'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => FiscalPeriodStatus::class,
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by')->withTrashed();
    }

    public function isClosed(): bool
    {
        return $this->status === FiscalPeriodStatus::Closed;
    }

    public function firstDay(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1);
    }
}
