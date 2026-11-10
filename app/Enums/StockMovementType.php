<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case PurchaseReturn = 'purchase_return';
    case SaleReturn = 'sale_return';
    case Adjustment = 'adjustment';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Pembelian',
            self::Sale => 'Penjualan',
            self::PurchaseReturn => 'Retur Pembelian',
            self::SaleReturn => 'Retur Penjualan',
            self::Adjustment => 'Penyesuaian Stok',
            self::Damaged => 'Barang Rusak',
            self::Lost => 'Barang Hilang',
            self::TransferOut => 'Transfer Keluar',
            self::TransferIn => 'Transfer Masuk',
        };
    }

    /** Jenis yang boleh dibuat tangan lewat menu Penyesuaian. Sisanya hanya dibuat oleh modul terkait. */
    public static function manual(): array
    {
        return [self::Adjustment, self::Damaged, self::Lost];
    }
}
