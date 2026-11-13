<?php

namespace App\Enums;

/** Status transfer stok. Dalam perjalanan = transfer antar cabang yang sudah dikirim, belum diterima. */
enum StockTransferStatus: string
{
    case InTransit = 'in_transit';
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::InTransit => 'Dalam perjalanan',
            self::Completed => 'Selesai',
            self::Voided => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InTransit => 'warning',
            self::Completed => 'success',
            self::Voided => 'neutral',
        };
    }
}
