<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Satu berkas lampiran dokumen. Disimpan dan dihapus lewat AttachmentService. */
class Attachment extends Model
{
    protected $fillable = ['disk', 'path', 'original_name', 'mime_type', 'size', 'description', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /** Bisa dipratinjau di browser (gambar atau PDF). */
    public function isPreviewable(): bool
    {
        return $this->isImage() || $this->isPdf();
    }

    /** "1,2 MB" / "350 KB". */
    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? Format::jumlah(round($this->size / 1048576, 1)).' MB'
            : Format::jumlah(max(1, round($this->size / 1024))).' KB';
    }

    /** @return array<string, mixed> data untuk komponen x-ui.attachments */
    public function toPanelArray(bool $canDelete): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'size' => $this->humanSize(),
            'uploader' => $this->uploader?->name,
            'date' => Format::tanggal($this->created_at, true),
            'kind' => $this->isImage() ? 'image' : ($this->isPdf() ? 'pdf' : 'file'),
            'previewUrl' => $this->isPreviewable() ? route('attachments.show', $this) : null,
            'downloadUrl' => route('attachments.download', $this),
            'deleteUrl' => $canDelete ? route('attachments.destroy', $this) : null,
        ];
    }
}
