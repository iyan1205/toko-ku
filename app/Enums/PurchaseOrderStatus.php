<?php

namespace App\Enums;

/** Alur purchase order: draft → disetujui → diterima sebagian → selesai, atau dibatalkan. */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Partial = 'partial';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Disetujui',
            self::Partial => 'Diterima sebagian',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Warna x-ui.badge. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Approved => 'info',
            self::Partial => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Barang dari PO ini boleh diterima. */
    public function canReceive(): bool
    {
        return in_array($this, [self::Approved, self::Partial], true);
    }
}
