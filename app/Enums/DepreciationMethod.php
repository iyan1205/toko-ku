<?php

namespace App\Enums;

/** Metode penyusutan aset tetap. */
enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';

    public function label(): string
    {
        return match ($this) {
            self::StraightLine => 'Garis lurus',
            self::DecliningBalance => 'Saldo menurun',
        };
    }
}
