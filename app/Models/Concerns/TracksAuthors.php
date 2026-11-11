<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isi created_by dan updated_by dengan pengguna yang sedang login. Proses tanpa login
 * (scheduler, queue) membiarkannya apa adanya, kecuali pemanggil mengisinya sendiri.
 */
trait TracksAuthors
{
    public static function bootTracksAuthors(): void
    {
        static::creating(function (self $model) {
            $id = auth()->id();
            $model->created_by ??= $id;
            $model->updated_by ??= $id ?? $model->created_by;
        });

        static::updating(function (self $model) {
            if (($id = auth()->id()) !== null && ! $model->isDirty('updated_by')) {
                $model->updated_by = $id;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
