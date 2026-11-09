<?php

namespace App\Enums;

use App\Models\Cuti;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockOpname;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokumen yang nomornya dibuat DocumentNumberService dan formatnya diatur di
 * Pengaturan → Penomoran Dokumen.
 *
 * Pola bawaan sama persis dengan format sebelum Fase 4, jadi nomor tidak berubah
 * sampai pemilik mengganti polanya.
 */
enum DocumentType: string
{
    case Sale = 'sale';
    case Purchase = 'purchase';
    case SupplierPayment = 'supplier_payment';
    case CustomerPayment = 'customer_payment';
    case StockOpname = 'stock_opname';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Penjualan kasir',
            self::Purchase => 'Pembelian',
            self::SupplierPayment => 'Pembayaran hutang supplier',
            self::CustomerPayment => 'Penerimaan piutang pelanggan',
            self::StockOpname => 'Stok opname',
            self::Leave => 'Pengajuan cuti & izin',
        };
    }

    public function defaultPattern(): string
    {
        return match ($this) {
            self::Sale => 'POS-{YYYY}{MM}{DD}-{####}',
            self::Purchase => 'PUR-{YYYY}{MM}{DD}-{####}',
            self::SupplierPayment => 'PAY-{YYYY}{MM}{DD}-{####}',
            self::CustomerPayment => 'RCV-{YYYY}{MM}{DD}-{####}',
            self::StockOpname => 'OPN-{YYYY}{MM}{DD}-{####}',
            self::Leave => 'CT-{#####}',
        };
    }

    public function defaultReset(): NumberingReset
    {
        return $this === self::Leave ? NumberingReset::Never : NumberingReset::Daily;
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Sale => Sale::class,
            self::Purchase => Purchase::class,
            self::SupplierPayment, self::CustomerPayment => Payment::class,
            self::StockOpname => StockOpname::class,
            self::Leave => Cuti::class,
        };
    }

    /** Kolom nomor di tabel dokumen (unik). */
    public function column(): string
    {
        return $this === self::Leave ? 'nomor' : 'number';
    }

    /** Panjang kolom nomor di database. */
    public function maxLength(): int
    {
        return $this === self::Leave ? 20 : 30;
    }
}
