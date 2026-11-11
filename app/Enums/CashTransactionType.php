<?php

namespace App\Enums;

/** Transaksi kas & bank di luar penjualan/pembelian. */
enum CashTransactionType: string
{
    case In = 'in';
    case Out = 'out';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Penerimaan kas',
            self::Out => 'Pengeluaran kas',
            self::Transfer => 'Transfer antar rekening',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::In => 'Masuk',
            self::Out => 'Keluar',
            self::Transfer => 'Transfer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::In => 'success',
            self::Out => 'danger',
            self::Transfer => 'info',
        };
    }

    public function documentType(): DocumentType
    {
        return match ($this) {
            self::In => DocumentType::CashReceipt,
            self::Out => DocumentType::CashDisbursement,
            self::Transfer => DocumentType::CashTransfer,
        };
    }

    /** Jenis dokumen di config/approval.php (penerimaan tidak memakai persetujuan). */
    public function approvalDocument(): string
    {
        return match ($this) {
            self::In => 'cash_in',
            self::Out => 'cash_out',
            self::Transfer => 'cash_transfer',
        };
    }

    /** Slug URL: transaksi-kas/buat/{slug}. */
    public function slug(): string
    {
        return match ($this) {
            self::In => 'masuk',
            self::Out => 'keluar',
            self::Transfer => 'transfer',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }
}
