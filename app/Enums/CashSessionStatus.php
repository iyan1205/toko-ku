<?php

namespace App\Enums;

/** Sesi kasir: terbuka (sedang dipakai menjual) atau ditutup (uang fisik sudah dihitung). */
enum CashSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Closed => 'Ditutup',
        };
    }

    public function color(): string
    {
        return $this === self::Open ? 'success' : 'neutral';
    }
}
