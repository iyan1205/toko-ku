<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu berkas impor: isi setiap baris + kesalahannya (pratinjau), lalu hasil proses.
 * Dihapus otomatis 30 hari setelah dibuat (php artisan model:prune, terjadwal harian).
 *
 * @property list<array{line: int, raw: array<string, mixed>, errors: list<string>}> $rows
 */
class ImportBatch extends Model
{
    use MassPrunable;

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_COMPLETED = 'completed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isPreviewed(): bool
    {
        return $this->status === self::STATUS_PREVIEWED;
    }

    public function statusLabel(): string
    {
        return $this->isPreviewed() ? 'Belum diproses' : 'Selesai';
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }
}
