<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Ekspor data besar yang dibuat queue job (GenerateListExport). Berkas disimpan di disk privat dan
 * hanya bisa diunduh pemintanya sampai expires_at; setelah itu dihapus (model:prune, terjadwal).
 */
class ExportFile extends Model
{
    use Prunable;

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** Berkas bisa diunduh selama 7 hari. */
    public const KEEP_DAYS = 7;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'branch_ids' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::COMPLETED && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::COMPLETED && ! $this->isReady() => 'Kedaluwarsa',
            default => match ($this->status) {
                self::QUEUED => 'Menunggu antrean',
                self::PROCESSING => 'Sedang dibuat',
                self::COMPLETED => 'Siap diunduh',
                default => 'Gagal',
            },
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->isReady() => 'success',
            $this->status === self::FAILED => 'danger',
            in_array($this->status, [self::QUEUED, self::PROCESSING], true) => 'warning',
            default => 'neutral',
        };
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS + 1));
    }

    protected function pruning(): void
    {
        if ($this->path) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
