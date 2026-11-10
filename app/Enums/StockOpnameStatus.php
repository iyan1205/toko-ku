<?php

namespace App\Enums;

/** Draft: hitungan tersimpan, stok belum berubah. Final: selisih sudah dibukukan ke stok. */
enum StockOpnameStatus: string
{
    case Draft = 'draft';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Final => 'Selesai',
        };
    }

    public function color(): string
    {
        return $this === self::Draft ? 'neutral' : 'success';
    }
}
