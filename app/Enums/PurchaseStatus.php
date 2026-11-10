<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Selesai',
            self::Voided => 'Dibatalkan',
        };
    }

    /** Warna x-ui.badge. */
    public function color(): string
    {
        return $this === self::Completed ? 'success' : 'danger';
    }
}
