<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Qris = 'qris';
    case Debit = 'debit';
    case Credit = 'credit';
    case EWallet = 'ewallet';
    case Receivable = 'receivable'; // hanya penjualan (Tahap 5): dicatat sebagai piutang pelanggan

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer',
            self::Qris => 'QRIS',
            self::Debit => 'Debit',
            self::Credit => 'Kredit',
            self::EWallet => 'E-Wallet',
            self::Receivable => 'Piutang',
        };
    }

    /** Seluruh metode tersedia di kasir. */
    public static function untukPenjualan(): array
    {
        return self::cases();
    }

    /** Metode yang masuk akal untuk membayar supplier (piutang hanya untuk pelanggan). */
    public static function untukPembelian(): array
    {
        return array_values(array_filter(self::cases(), fn (self $m) => $m !== self::Receivable));
    }
}
