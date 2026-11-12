<?php

namespace App\Enums;

enum FamilyRelation: string
{
    case Spouse = 'spouse';
    case Child = 'child';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spouse => 'Suami/istri',
            self::Child => 'Anak',
            self::Parent => 'Orang tua',
            self::Sibling => 'Saudara kandung',
            self::Other => 'Lainnya',
        };
    }
}
