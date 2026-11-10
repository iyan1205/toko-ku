<?php

namespace App\Enums;

/** Draft: belum berpengaruh. Disetujui: stok keluar dan hutang berkurang. */
enum PurchaseReturnStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Disetujui',
        };
    }

    public function color(): string
    {
        return $this === self::Draft ? 'neutral' : 'success';
    }
}
