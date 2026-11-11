<?php

namespace App\Enums;

/** Faktur manual: penjualan (piutang pelanggan) atau tagihan pembelian (hutang supplier). */
enum InvoiceType: string
{
    case Sales = 'sales';
    case Purchase = 'purchase';

    public function label(): string
    {
        return $this === self::Sales ? 'Faktur penjualan' : 'Tagihan pembelian';
    }

    public function documentType(): DocumentType
    {
        return $this === self::Sales ? DocumentType::SalesInvoice : DocumentType::PurchaseBill;
    }

    /** Awalan route: sales-invoices.* / purchase-bills.*. */
    public function routePrefix(): string
    {
        return $this === self::Sales ? 'sales-invoices' : 'purchase-bills';
    }

    /** Izin: pos.invoices.* / purchasing.bills.*. */
    public function permission(string $action): string
    {
        return ($this === self::Sales ? 'pos.invoices.' : 'purchasing.bills.').$action;
    }
}
