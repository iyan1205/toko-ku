<?php

namespace App\Enums;

/** Bagian saldo awal: saldo akun, nilai persediaan per gudang, piutang per pelanggan, hutang per supplier. */
enum OpeningBalanceKind: string
{
    case Account = 'account';
    case Inventory = 'inventory';
    case Receivable = 'receivable';
    case Payable = 'payable';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Saldo akun',
            self::Inventory => 'Persediaan',
            self::Receivable => 'Piutang pelanggan',
            self::Payable => 'Hutang supplier',
        };
    }
}
