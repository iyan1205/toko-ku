<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tutup buku satu tahun (jurnal penutup ke Laba Ditahan per cabang). */
class FiscalYearClosing extends Model
{
    use SoftDeletes, TracksAuthors;

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['fiscal_year'];

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer', 'net_income' => 'decimal:2', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $closing) => $closing->company_id ??= Company::query()->where('is_default', true)->value('id'));
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

    public function getNumberAttribute(): string
    {
        return "Tutup buku {$this->fiscal_year}";
    }
}
