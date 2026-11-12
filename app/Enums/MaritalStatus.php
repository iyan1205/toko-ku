<?php

namespace App\Enums;

enum MaritalStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Widowed = 'widowed';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Belum menikah',
            self::Married => 'Menikah',
            self::Divorced => 'Cerai hidup',
            self::Widowed => 'Cerai mati',
        };
    }
}
