<?php

namespace App\Enums;

/** Draft: diajukan, belum berpengaruh. Disetujui: stok kembali dan uang/piutang dikoreksi. */
enum SaleReturnStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Menunggu persetujuan',
            self::Approved => 'Disetujui',
        };
    }

    public function color(): string
    {
        return $this === self::Draft ? 'warning' : 'success';
    }
}
