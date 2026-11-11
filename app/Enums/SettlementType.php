<?php

namespace App\Enums;

/**
 * Pelunasan piutang/hutang: penerimaan dari pelanggan, pembayaran ke supplier, nota kredit
 * (mengurangi piutang tanpa uang), dan nota debit (mengurangi hutang tanpa uang).
 */
enum SettlementType: string
{
    case Receipt = 'receipt';
    case Payment = 'payment';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Penerimaan piutang',
            self::Payment => 'Pembayaran hutang',
            self::CreditNote => 'Nota kredit',
            self::DebitNote => 'Nota debit',
        };
    }

    /** Sisi pelanggan (piutang) atau supplier (hutang). */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Receipt, self::CreditNote], true);
    }

    /** Membawa uang (dan bisa uang muka/giro); nota hanya mengurangi faktur. */
    public function isCash(): bool
    {
        return in_array($this, [self::Receipt, self::Payment], true);
    }

    public function documentType(): DocumentType
    {
        return match ($this) {
            self::Receipt => DocumentType::CustomerSettlement,
            self::Payment => DocumentType::SupplierSettlement,
            self::CreditNote => DocumentType::CreditNote,
            self::DebitNote => DocumentType::DebitNote,
        };
    }

    public function color(): string
    {
        return $this->isCash() ? 'success' : 'info';
    }

    /** Awalan route: receivable-settlements.* (piutang) / payable-settlements.* (hutang). */
    public function routePrefix(): string
    {
        return $this->isReceivable() ? 'receivable-settlements' : 'payable-settlements';
    }

    /** Izin pos.receivables.* untuk piutang, purchasing.payables.* untuk hutang. */
    public function permission(string $action): string
    {
        return ($this->isReceivable() ? 'pos.receivables.' : 'purchasing.payables.').$action;
    }

    /** @return list<self> */
    public static function forSide(bool $receivable): array
    {
        return $receivable ? [self::Receipt, self::CreditNote] : [self::Payment, self::DebitNote];
    }
}
