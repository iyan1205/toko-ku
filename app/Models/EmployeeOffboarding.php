<?php

namespace App\Models;

use App\Enums\TerminationType;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Offboarding karyawan: alasan berhenti, hari terakhir kerja, dan checklist pengembalian. */
class EmployeeOffboarding extends Model
{
    use SoftDeletes, TracksAuthors;

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::IN_PROGRESS => 'Berjalan', self::COMPLETED => 'Selesai', self::CANCELLED => 'Dibatalkan'];

    protected $fillable = ['termination_type', 'notice_date', 'last_working_date', 'reason', 'deactivate_user'];

    protected function casts(): array
    {
        return [
            'termination_type' => TerminationType::class,
            'notice_date' => 'date',
            'last_working_date' => 'date',
            'deactivate_user' => 'boolean',
            'completed_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeOffboardingItem::class)->orderBy('id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function isInProgress(): bool
    {
        return $this->status === self::IN_PROGRESS;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::COMPLETED => 'success',
            self::CANCELLED => 'neutral',
            default => 'warning',
        };
    }

    /** @param array{cari?: ?string, status?: ?string} $f */
    public function scopeFilter(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($f['cari'] ?? null, fn (Builder $q, $kata) => $q->whereHas('employee', fn (Builder $w) => $w->withTrashed()
                ->where(fn (Builder $x) => $x->where('name', 'like', "%{$kata}%")->orWhere('code', 'like', "%{$kata}%"))));
    }
}
