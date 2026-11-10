<?php

namespace App\Services\Accounting;

use App\Enums\PaymentMethod;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Model;

/**
 * Jurnal otomatis dari transaksi modul lain, dipanggil listener App\Listeners\CreateAutoJournal.
 *
 *   Penjualan        D Kas/Bank (dibayar saat transaksi), Piutang (sisa), HPP   | K Penjualan, PPN Keluaran, Persediaan
 *   Barang diterima  D Persediaan, PPN Masukan                                    | K Kas/Bank (dibayar saat transaksi), Hutang (sisa)
 *   Pelunasan piutang D Kas/Bank | K Piutang        Bayar hutang D Hutang | K Kas/Bank
 *   Retur penjualan  D Retur Penjualan, PPN Keluaran, Persediaan                  | K Piutang, Kas/Bank (uang kembali), HPP
 *   Retur pembelian  D Hutang, Piutang Lain-lain (uang dari supplier)             | K Persediaan
 *   Pembatalan       jurnal pembalik untuk jurnal dokumen dan jurnal pembayarannya
 *
 * Pembayaran yang dibuat bersamaan dengan transaksi sudah masuk jurnal transaksinya, jadi
 * event pembayarannya (atCheckout = true) tidak dijurnal lagi. Akun diambil dari
 * config/accounting.php. Setiap kejadian hanya dijurnal sekali (lihat JournalService).
 */
class AutoJournalService
{
    /** @var array<string, Account> */
    private array $accounts = [];

    public function __construct(private readonly JournalService $journals) {}

    /** Menyala lewat Pengaturan → Akuntansi (bawaan: menyala) dan bagan akun sudah dipasang. */
    public function enabled(): bool
    {
        return setting('accounting.auto_journal', '1') === '1' && Account::query()->exists();
    }

    public function forSale(Sale $sale): Journal
    {
        $sale->loadMissing(['items', 'customer']);
        $lines = new JournalLines;

        // Pembayaran yang sudah ada saat event ini diproses = pembayaran saat transaksi.
        $paid = 0;
        foreach ($sale->payments()->whereNull('voided_at')->get() as $payment) {
            $lines->debit($this->paymentAccount($payment->method), Uang::sen($payment->amount));
            $paid += Uang::sen($payment->amount);
        }

        $total = Uang::sen($sale->grand_total);
        $tax = Uang::sen($sale->tax);
        $lines->debit($this->account('receivable'), max(0, $total - $paid));
        $lines->credit($this->account('sales'), $total - $tax);
        $lines->credit($this->account('vat_out'), $tax);

        $cost = $sale->items->sum(fn ($item) => Uang::kali(Uang::milli($item->quantity), Uang::sen($item->cost_price)));
        $lines->debit($this->account('cogs'), $cost, 'Harga pokok penjualan');
        $lines->credit($this->account('inventory'), $cost, 'Persediaan keluar');

        $pelanggan = $sale->customer && ! $sale->customer->is_default ? " · {$sale->customer->name}" : '';

        return $this->journals->post($sale->sold_at, "Penjualan {$sale->number}{$pelanggan}", $lines->all(), $sale->user, $sale, 'created');
    }

    /** @return list<Journal> jurnal pembalik yang dibuat */
    public function forSaleVoid(Sale $sale): array
    {
        return $this->reverseDocument($sale, $sale->payments()->pluck('id')->all(), "Pembatalan penjualan {$sale->number}", $sale->voidedBy);
    }

    public function forSaleReturn(SaleReturn $return): Journal
    {
        $return->loadMissing(['sale', 'items.saleItem']);
        $sale = $return->sale;
        $lines = new JournalLines;

        // Nilai retur sudah termasuk bagian pajaknya (dibagi sebanding total transaksi).
        $value = Uang::sen($return->total);
        $tax = Uang::sen($sale->grand_total) > 0 ? Uang::kaliBagi($value, Uang::sen($sale->tax), Uang::sen($sale->grand_total)) : 0;
        $lines->debit($this->account('sales_return'), $value - $tax);
        $lines->debit($this->account('vat_out'), $tax);
        $lines->credit($this->account('receivable'), Uang::sen($return->receivable_reduction), 'Mengurangi piutang transaksi');
        $lines->credit($this->paymentAccount($return->refund_method ?? PaymentMethod::Cash), Uang::sen($return->refund_amount), 'Uang dikembalikan ke pelanggan');

        $cost = $return->items->sum(fn ($item) => Uang::kali(Uang::milli($item->quantity), Uang::sen($item->saleItem?->cost_price ?? 0)));
        $lines->debit($this->account('inventory'), $cost, 'Persediaan kembali');
        $lines->credit($this->account('cogs'), $cost, 'Harga pokok penjualan');

        return $this->journals->post($return->approved_at ?? $return->return_date, "Retur penjualan {$return->number} atas {$sale->number}", $lines->all(), $return->approvedBy ?? $return->user, $return, 'approved');
    }

    public function forPurchase(Purchase $purchase, ?GoodsReceipt $receipt = null): Journal
    {
        $purchase->loadMissing('supplier');
        $lines = new JournalLines;

        $total = Uang::sen($purchase->grand_total);
        $tax = Uang::sen($purchase->tax);
        $lines->debit($this->account('inventory'), $total - $tax);
        $lines->debit($this->account('vat_in'), $tax);

        $paid = 0;
        foreach ($purchase->payments()->whereNull('voided_at')->get() as $payment) {
            $lines->credit($this->paymentAccount($payment->method), Uang::sen($payment->amount));
            $paid += Uang::sen($payment->amount);
        }
        $lines->credit($this->account('payable'), max(0, $total - $paid));

        $asal = $receipt ? " (penerimaan {$receipt->number})" : '';

        return $this->journals->post($purchase->purchase_date, "Pembelian {$purchase->number} · {$purchase->supplier?->name}{$asal}", $lines->all(), $purchase->user, $purchase, 'created');
    }

    /** @return list<Journal> */
    public function forPurchaseVoid(Purchase $purchase): array
    {
        return $this->reverseDocument($purchase, $purchase->payments()->pluck('id')->all(), "Pembatalan pembelian {$purchase->number}", $purchase->voidedBy);
    }

    public function forPurchaseReturn(PurchaseReturn $return): Journal
    {
        $return->loadMissing('purchase');
        $lines = new JournalLines;

        $value = Uang::sen($return->total);
        $refund = Uang::sen($return->refund_amount);
        $lines->debit($this->account('payable'), $value - $refund, 'Mengurangi hutang faktur');
        $lines->debit($this->account('other_receivable'), $refund, 'Uang retur yang harus dikembalikan supplier');
        $lines->credit($this->account('inventory'), $value, 'Persediaan keluar ke supplier');

        return $this->journals->post($return->approved_at ?? $return->return_date, "Retur pembelian {$return->number} atas {$return->purchase->number}", $lines->all(), $return->approvedBy ?? $return->user, $return, 'approved');
    }

    public function forCustomerPayment(Payment $payment, Sale $sale): Journal
    {
        $lines = new JournalLines;
        $lines->debit($this->paymentAccount($payment->method), Uang::sen($payment->amount));
        $lines->credit($this->account('receivable'), Uang::sen($payment->amount));

        return $this->journals->post($payment->paid_at, "Pelunasan piutang {$payment->number} atas {$sale->number}", $lines->all(), $payment->user, $payment, 'created');
    }

    public function forSupplierPayment(Payment $payment, Purchase $purchase): Journal
    {
        $lines = new JournalLines;
        $lines->debit($this->account('payable'), Uang::sen($payment->amount));
        $lines->credit($this->paymentAccount($payment->method), Uang::sen($payment->amount));

        return $this->journals->post($payment->paid_at, "Pembayaran hutang {$payment->number} atas {$purchase->number}", $lines->all(), $payment->user, $payment, 'created');
    }

    /** Akun sistem berdasarkan kunci di config/accounting.php. */
    public function account(string $key): Account
    {
        if (isset($this->accounts[$key])) {
            return $this->accounts[$key];
        }

        $code = config("accounting.accounts.{$key}");
        $account = $code ? Account::query()->where('code', $code)->first() : null;

        if ($account === null || ! $account->isPostable()) {
            throw new AturanBisnisException("Akun {$code} untuk jurnal otomatis ({$key}) tidak ditemukan atau tidak aktif. Periksa Bagan Akun.");
        }

        return $this->accounts[$key] = $account;
    }

    public function paymentAccount(PaymentMethod $method): Account
    {
        return $this->account(config("accounting.payment_accounts.{$method->value}", 'bank'));
    }

    /**
     * Balik jurnal dokumen dan jurnal pembayarannya yang belum dibalik.
     *
     * @param  list<int>  $paymentIds
     * @return list<Journal>
     */
    private function reverseDocument(Model $document, array $paymentIds, string $description, ?User $user): array
    {
        $journals = Journal::query()->withoutGlobalScope(BranchScope::class)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->where('source_event', 'created')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey()))
                ->orWhere(fn ($w) => $w->where('source_type', (new Payment)->getMorphClass())->whereIn('source_id', $paymentIds ?: [0])))
            ->with('source')
            ->orderBy('id')
            ->get();

        return $journals->map(fn (Journal $journal) => $this->journals->reverse(
            $journal, "{$description} (membalik {$journal->number})", $user, $journal->source, 'voided',
        ))->all();
    }
}
