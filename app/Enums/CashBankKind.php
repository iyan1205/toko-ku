<?php

namespace App\Enums;

/** Jenis rekening kas & bank. Kas kecil punya plafon dan diisi ulang dari rekening lain. */
enum CashBankKind: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case PettyCash = 'petty_cash';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Kas',
            self::Bank => 'Bank',
            self::PettyCash => 'Kas kecil',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'banknotes',
            self::Bank => 'building-library',
            self::PettyCash => 'wallet',
        };
    }

    /** Metode pembayaran yang dicatat di baris payments untuk uang lewat rekening ini. */
    public function paymentMethod(): PaymentMethod
    {
        return $this === self::Bank ? PaymentMethod::Transfer : PaymentMethod::Cash;
    }
}
