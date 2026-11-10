<?php

namespace App\Enums;

/** Status kunci periode bulanan. Bulan tanpa baris di fiscal_periods dianggap terbuka. */
enum FiscalPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return $this === self::Open ? 'Terbuka' : 'Ditutup';
    }

    public function color(): string
    {
        return $this === self::Open ? 'success' : 'danger';
    }
}
