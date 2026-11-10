<?php

namespace App\Models\Concerns;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Exceptions\AturanBisnisException;
use App\Models\User;
use App\Services\Settings\DocumentNumberService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Status baku dokumen bisnis: draft → submitted → approved → posted, atau cancelled
 * (App\Enums\DocumentStatus), dengan nomor dari Pengaturan → Penomoran Dokumen.
 *
 *   - Model menyediakan documentType() (DocumentType) dan documentDateColumn().
 *   - Nomor dibuat assignDocumentNumber() saat dokumen pertama kali disimpan (di dalam transaksi).
 *   - moveToStatus() menolak perpindahan yang tidak sah (lihat DocumentStatus::canMoveTo) dan
 *     mengisi kolom jejak bila ada: submitted_at, approved_at/by, posted_at/by, cancelled_at/by.
 *   - Dokumen posted/cancelled tidak bisa diubah isinya maupun dihapus; hanya draf yang bisa dihapus.
 *
 * Dipakai bersama HasApproval: kait persetujuan bawaan memindahkan status ke submitted/approved/draft.
 */
trait HasDocumentStatus
{
    /** Kolom yang tetap boleh berubah setelah dokumen terkunci (jejak status). */
    private static array $documentStatusTrail = [
        'status', 'updated_at', 'submitted_at', 'approved_at', 'approved_by',
        'posted_at', 'posted_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    /** @var array<string, array<string, int>> kolom tabel, agar tidak dibaca ulang setiap pindah status */
    private static array $documentStatusColumns = [];

    abstract public function documentType(): DocumentType;

    abstract public function documentDateColumn(): string;

    public static function bootHasDocumentStatus(): void
    {
        static::updating(function (self $model) {
            $before = $model->getOriginal('status');
            $before = $before instanceof DocumentStatus ? $before : DocumentStatus::tryFrom((string) $before);

            if ($before?->isLocked() && array_diff(array_keys($model->getDirty()), self::$documentStatusTrail) !== []) {
                throw new AturanBisnisException("{$model->documentLabel()} sudah {$before->label()} dan tidak bisa diubah. Koreksi lewat pembatalan atau jurnal balik.");
            }
        });

        static::deleting(function (self $model) {
            if ($model->documentStatus() !== DocumentStatus::Draft) {
                throw new AturanBisnisException("Hanya draf yang bisa dihapus. {$model->documentLabel()} berstatus {$model->documentStatus()->label()}.");
            }
        });
    }

    public function initializeHasDocumentStatus(): void
    {
        $this->mergeCasts(['status' => DocumentStatus::class]);
    }

    public function documentStatus(): DocumentStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof DocumentStatus ? $status : (DocumentStatus::tryFrom((string) $status) ?? DocumentStatus::Draft);
    }

    /** "Jurnal JU-20261110-0001". */
    public function documentLabel(): string
    {
        return trim($this->documentType()->label().' '.($this->getAttribute($this->documentType()->column()) ?? ''));
    }

    public function isEditable(): bool
    {
        return $this->documentStatus() === DocumentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->documentStatus() === DocumentStatus::Posted;
    }

    /** Isi nomor dokumen bila belum ada. Wajib dipanggil di dalam DB::transaction() penyimpannya. */
    public function assignDocumentNumber(): string
    {
        $column = $this->documentType()->column();

        if (blank($this->getAttribute($column))) {
            $this->setAttribute($column, app(DocumentNumberService::class)->next(
                $this->documentType(),
                $this->getAttribute($this->documentDateColumn()) ?? now(),
            ));
        }

        return $this->getAttribute($column);
    }

    /** Pindah status dan simpan. Melempar AturanBisnisException bila perpindahan tidak sah. */
    public function moveToStatus(DocumentStatus $to, ?User $by = null, ?string $reason = null): void
    {
        $from = $this->documentStatus();

        if (! $from->canMoveTo($to)) {
            throw new AturanBisnisException("{$this->documentLabel()} berstatus {$from->label()} dan tidak bisa menjadi {$to->label()}.");
        }

        $trail = match ($to) {
            DocumentStatus::Submitted => ['submitted_at' => now()],
            DocumentStatus::Approved => ['approved_at' => now(), 'approved_by' => $by?->getKey()],
            DocumentStatus::Posted => ['posted_at' => now(), 'posted_by' => $by?->getKey()],
            DocumentStatus::Cancelled => ['cancelled_at' => now(), 'cancelled_by' => $by?->getKey(), 'cancel_reason' => $reason],
            DocumentStatus::Draft => ['submitted_at' => null, 'approved_at' => null, 'approved_by' => null],
        };

        $columns = self::$documentStatusColumns[$this->getTable()] ??= array_flip($this->getConnection()->getSchemaBuilder()->getColumnListing($this->getTable()));
        $this->forceFill(['status' => $to] + array_intersect_key($trail, $columns))->save();
    }

    public function scopeWithStatus(Builder $query, DocumentStatus ...$statuses): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), array_map(fn (DocumentStatus $s) => $s->value, $statuses));
    }
}
