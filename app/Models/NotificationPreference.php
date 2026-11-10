<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pilihan kanal notifikasi seorang pengguna untuk satu jenis notifikasi (config/notification-types.php). */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'type', 'database', 'mail'];

    protected function casts(): array
    {
        return [
            'database' => 'boolean',
            'mail' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
