<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catat tambah/ubah/hapus model ke log aktivitas (spatie/laravel-activitylog), dengan
 * deskripsi berbahasa Indonesia, misal: Produk "Ayam Geprek" diubah.
 *
 * Model yang memakai trait ini wajib mendefinisikan:
 *   - activityLogName(): kelompok log, misal 'inventori'
 *   - activityLogLabel(): nama jenis data, misal 'Produk'
 *   - activityLogAttributes(): kolom yang dicatat. Tulis eksplisit; JANGAN masukkan kolom
 *     rahasia (password, token).
 * Hanya kolom yang berubah yang dicatat; perubahan tanpa kolom tercatat tidak membuat log.
 */
trait RecordsActivity
{
    use LogsActivity;

    abstract protected function activityLogName(): string;

    abstract protected function activityLogLabel(): string;

    /** @return list<string> */
    abstract protected function activityLogAttributes(): array;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->activityLogName())
            ->logOnly($this->activityLogAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => sprintf(
                '%s "%s" %s',
                $this->activityLogLabel(),
                $this->getAttribute('name') ?? $this->getAttribute('nama') ?? '#'.$this->getKey(),
                match ($event) {
                    'created' => 'ditambahkan',
                    'updated' => 'diubah',
                    'deleted' => 'dihapus',
                    'restored' => 'dipulihkan',
                    default => $event,
                },
            ));
    }
}
