<?php

namespace App\Enums;

use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\Cuti;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Settlement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
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
    case StockTransfer = 'stock_transfer';
    case PurchaseOrder = 'purchase_order';
    case GoodsReceipt = 'goods_receipt';
    case PurchaseReturn = 'purchase_return';
    case CashSession = 'cash_session';
    case SaleReturn = 'sale_return';
    case CustomerRefund = 'customer_refund';
    case Journal = 'journal';
    case Leave = 'leave';
    case OpeningBalance = 'opening_balance';
    case CashReceipt = 'cash_receipt';
    case CashDisbursement = 'cash_disbursement';
    case CashTransfer = 'cash_transfer';
    case SalesInvoice = 'sales_invoice';
    case PurchaseBill = 'purchase_bill';
    case CustomerSettlement = 'customer_settlement';
    case SupplierSettlement = 'supplier_settlement';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Penjualan kasir',
            self::Purchase => 'Pembelian',
            self::SupplierPayment => 'Pembayaran hutang supplier',
            self::CustomerPayment => 'Penerimaan piutang pelanggan',
            self::StockOpname => 'Stok opname',
            self::StockTransfer => 'Transfer stok',
            self::PurchaseOrder => 'Purchase order',
            self::GoodsReceipt => 'Penerimaan barang',
            self::PurchaseReturn => 'Retur pembelian',
            self::CashSession => 'Sesi kasir',
            self::SaleReturn => 'Retur penjualan',
            self::CustomerRefund => 'Pengembalian uang ke pelanggan',
            self::Journal => 'Jurnal umum',
            self::Leave => 'Pengajuan cuti & izin',
            self::OpeningBalance => 'Saldo awal',
            self::CashReceipt => 'Penerimaan kas',
            self::CashDisbursement => 'Pengeluaran kas',
            self::CashTransfer => 'Transfer antar rekening',
            self::SalesInvoice => 'Faktur penjualan (manual)',
            self::PurchaseBill => 'Tagihan pembelian (manual)',
            self::CustomerSettlement => 'Pelunasan piutang',
            self::SupplierSettlement => 'Pembayaran hutang (gabungan)',
            self::CreditNote => 'Nota kredit',
            self::DebitNote => 'Nota debit',
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
            self::StockTransfer => 'TRF-{YYYY}{MM}{DD}-{####}',
            self::PurchaseOrder => 'PO-{YYYY}{MM}{DD}-{####}',
            self::GoodsReceipt => 'GRN-{YYYY}{MM}{DD}-{####}',
            self::PurchaseReturn => 'RTB-{YYYY}{MM}{DD}-{####}',
            self::CashSession => 'SES-{YYYY}{MM}{DD}-{####}',
            self::SaleReturn => 'RTJ-{YYYY}{MM}{DD}-{####}',
            self::CustomerRefund => 'RFD-{YYYY}{MM}{DD}-{####}',
            self::Journal => 'JU-{YYYY}{MM}{DD}-{####}',
            self::Leave => 'CT-{#####}',
            self::OpeningBalance => 'SA-{YYYY}{MM}{DD}-{####}',
            self::CashReceipt => 'BKM-{YYYY}{MM}{DD}-{####}',
            self::CashDisbursement => 'BKK-{YYYY}{MM}{DD}-{####}',
            self::CashTransfer => 'TRB-{YYYY}{MM}{DD}-{####}',
            self::SalesInvoice => 'INV-{YYYY}{MM}{DD}-{####}',
            self::PurchaseBill => 'BIL-{YYYY}{MM}{DD}-{####}',
            self::CustomerSettlement => 'PLP-{YYYY}{MM}{DD}-{####}',
            self::SupplierSettlement => 'PLH-{YYYY}{MM}{DD}-{####}',
            self::CreditNote => 'NK-{YYYY}{MM}{DD}-{####}',
            self::DebitNote => 'ND-{YYYY}{MM}{DD}-{####}',
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
            self::SupplierPayment, self::CustomerPayment, self::CustomerRefund => Payment::class,
            self::StockOpname => StockOpname::class,
            self::StockTransfer => StockTransfer::class,
            self::PurchaseOrder => PurchaseOrder::class,
            self::GoodsReceipt => GoodsReceipt::class,
            self::PurchaseReturn => PurchaseReturn::class,
            self::CashSession => CashSession::class,
            self::SaleReturn => SaleReturn::class,
            self::Journal => Journal::class,
            self::Leave => Cuti::class,
            self::OpeningBalance => OpeningBalance::class,
            self::CashReceipt, self::CashDisbursement, self::CashTransfer => CashTransaction::class,
            self::SalesInvoice, self::PurchaseBill => Invoice::class,
            self::CustomerSettlement, self::SupplierSettlement, self::CreditNote, self::DebitNote => Settlement::class,
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
