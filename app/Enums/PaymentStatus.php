<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'LUNAS',
            self::Unpaid => 'HUTANG',
            self::Partial => 'SEBAGIAN',
        };
    }

    /** Di penjualan, belum dibayar disebut PIUTANG (di pembelian: HUTANG). */
    public function labelPenjualan(): string
    {
        return $this === self::Unpaid ? 'PIUTANG' : $this->label();
    }

    public static function dari(int $dibayarSen, int $sisaSen): self
    {
        return match (true) {
            $sisaSen <= 0 => self::Paid,
            $dibayarSen <= 0 => self::Unpaid,
            default => self::Partial,
        };
    }
}
