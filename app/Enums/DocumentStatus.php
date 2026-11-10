<?php

namespace App\Enums;

/**
 * Status baku dokumen bisnis (trait HasDocumentStatus):
 * draft → submitted (menunggu persetujuan) → approved → posted, atau cancelled.
 * Dokumen posted tidak bisa diubah atau dihapus; koreksi lewat pembatalan/jurnal balik.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Menunggu persetujuan',
            self::Approved => 'Disetujui',
            self::Posted => 'Diposting',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Warna x-ui.badge. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Submitted => 'warning',
            self::Approved => 'info',
            self::Posted => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Status yang boleh dituju dari status ini. */
    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::Submitted, self::Approved, self::Posted, self::Cancelled],
            self::Submitted => [self::Draft, self::Approved, self::Cancelled],
            self::Approved => [self::Posted, self::Cancelled],
            self::Posted => [self::Cancelled],
            self::Cancelled => [],
        }, true);
    }

    /** Dokumen terkunci: isi tidak boleh diubah lagi. */
    public function isLocked(): bool
    {
        return in_array($this, [self::Posted, self::Cancelled], true);
    }
}
