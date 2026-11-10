<?php

namespace App\Enums;

/** Sisi saldo normal akun: bertambah di debit atau di kredit. */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return $this === self::Debit ? 'Debit' : 'Kredit';
    }
}
