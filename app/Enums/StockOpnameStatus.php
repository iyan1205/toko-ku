<?php

namespace App\Enums;

/**
 * Draft: hitungan tersimpan, stok belum berubah. Submitted: diajukan lewat alur persetujuan.
 * Final: selisih sudah dibukukan ke stok.
 */
enum StockOpnameStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Menunggu persetujuan',
            self::Final => 'Selesai',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Submitted => 'warning',
            self::Final => 'success',
        };
    }
}
