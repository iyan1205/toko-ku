<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use App\Services\Documents\AttachmentService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Dokumen yang bisa diberi lampiran (config/attachments.php: izin lihat & ubah per jenis dokumen).
 * Unggah, buka, dan hapus lewat App\Services\Documents\AttachmentService dan komponen x-ui.attachments.
 */
trait HasAttachments
{
    public static function bootHasAttachments(): void
    {
        // Lampiran ikut terhapus bila dokumennya dihapus permanen (misal draft PO dihapus).
        static::deleted(function (self $model) {
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                app(AttachmentService::class)->deleteAllFor($model);
            }
        });
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    /** Izin untuk aksi 'view' atau 'update' lampiran dokumen ini. */
    public function attachmentPermission(string $action): ?string
    {
        return config("attachments.types.{$this->getMorphClass()}.{$action}");
    }
}
