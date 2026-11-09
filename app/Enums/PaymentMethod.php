<?php

namespace App\Enums;

use App\Services\Settings\PaymentMethodService;
use Illuminate\Database\QueryException;

/**
 * Metode pembayaran. Logika khusus tiap metode (kembalian tunai, piutang) ada di kode;
 * nama tampilan, aktif/nonaktif, dan urutan di kasir diatur di Pengaturan → Metode Pembayaran.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Qris = 'qris';
    case Debit = 'debit';
    case Credit = 'credit';
    case EWallet = 'ewallet';
    case Receivable = 'receivable'; // hanya penjualan (Tahap 5): dicatat sebagai piutang pelanggan

    /** Nama tampilan dari Pengaturan (misal "QRIS BCA"); nama bawaan bila belum diatur. */
    public function label(): string
    {
        try {
            return app(PaymentMethodService::class)->label($this);
        } catch (QueryException) {
            // Tabel payment_methods belum ada (migration belum dijalankan).
            return $this->defaultLabel();
        }
    }

    public function defaultLabel(): string
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

    /**
     * Metode yang aktif di kasir, sesuai urutan di Pengaturan.
     *
     * @return list<self>
     */
    public static function untukPenjualan(): array
    {
        return app(PaymentMethodService::class)->forSales();
    }

    /**
     * Metode untuk bayar di muka (DP) penjualan piutang: metode kasir yang aktif selain Piutang.
     *
     * @return list<self>
     */
    public static function untukUangMuka(): array
    {
        return array_values(array_filter(self::untukPenjualan(), fn (self $m) => $m !== self::Receivable));
    }

    /**
     * Metode yang masuk akal untuk membayar supplier (piutang hanya untuk pelanggan).
     * Tidak terpengaruh pengaturan aktif/nonaktif di kasir.
     *
     * @return list<self>
     */
    public static function untukPembelian(): array
    {
        return array_values(array_filter(self::cases(), fn (self $m) => $m !== self::Receivable));
    }
}
