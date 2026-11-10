<?php

namespace App\Enums;

/** Penyetuju satu tahap: siapa pun dengan peran tertentu, atau satu pengguna tertentu. */
enum ApproverType: string
{
    case Role = 'role';
    case User = 'user';

    public function label(): string
    {
        return $this === self::Role ? 'Peran' : 'Pengguna';
    }
}
