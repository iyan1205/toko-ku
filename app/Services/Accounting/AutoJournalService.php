<?php

namespace App\Services\Accounting;

use App\Enums\PaymentMethod;
use App\Enums\SettlementType;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Scopes\BranchScope;
use App\Models\Settlement;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Model;

/**
 * Jurnal otomatis dari transaksi modul lain, dipanggil listener App\Listeners\CreateAutoJournal.
 *
 *   Penjualan        D Kas/Bank (dibayar saat transaksi), Piutang (sisa), Diskon*, HPP | K Penjualan, PPN Keluaran, Persediaan
 *   Barang diterima  D Persediaan, PPN Masukan                                    | K Kas/Bank (dibayar saat transaksi), Hutang (sisa)
 *   Pelunasan piutang D Kas/Bank | K Piutang        Bayar hutang D Hutang | K Kas/Bank
 *   Retur penjualan  D Retur Penjualan, PPN Keluaran, Persediaan                  | K Piutang, Kas/Bank (uang kembali), HPP
 *   Retur pembelian  D Hutang, Piutang Lain-lain (uang dari supplier)             | K Persediaan
 *   Stok opname      selisih lebih: D Persediaan | K Selisih Persediaan; selisih kurang sebaliknya
 *   Penyesuaian stok (manual, rusak, hilang) sama seperti stok opname, per mutasi
 *   Transfer stok    hanya antar cabang: cabang asal D Rekening Antar Cabang | K Persediaan,
 *                    cabang tujuan D Persediaan | K Rekening Antar Cabang
 *   Pembatalan       jurnal pembalik untuk jurnal dokumen dan jurnal pembayarannya
 *   Faktur manual    penjualan: D Piutang | K pendapatan per baris, PPN Keluaran;
 *                    tagihan: D beban per baris, PPN Masukan | K Hutang
 *   Pelunasan        penerimaan: D Kas/Bank (atau Giro Diterima), Uang Muka dipakai | K Piutang, Uang Muka baru
 *                    pembayaran: D Hutang, Uang Muka baru | K Kas/Bank (atau Hutang Giro), Uang Muka dipakai
 *                    nota kredit: D akun nota | K Piutang;  nota debit: D Hutang | K akun nota
 *   Giro cair        D rekening | K Giro Diterima (atau D Hutang Giro | K rekening)
 *
 *   * Diskon dipisah ke akun Diskon Penjualan bila peran itu dipetakan; bila tidak, penjualan
 *     dicatat bersih setelah diskon.
 *
 * Pembayaran yang dibuat bersamaan dengan transaksi sudah masuk jurnal transaksinya, jadi
 * event pembayarannya (atCheckout = true) tidak dijurnal lagi. Akun diambil dari pemetaan di
 * Pengaturan → Akuntansi (AccountMappingService). Nilai barang (opname, penyesuaian, transfer)
 * = jumlah x harga pokok produk saat itu. Setiap kejadian hanya dijurnal sekali (JournalService).
 */
class AutoJournalService
{
    /** @var array<string, Account> */
    private array $accounts = [];

    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountMappingService $mapping,
    ) {}

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
        $net = $total - $tax;
        $lines->debit($this->account('receivable'), max(0, $total - $paid));

        // Diskon dipisah bila akunnya dipetakan. Harga termasuk pajak: hanya bagian di luar pajak.
        $discount = Uang::sen($sale->item_discount) + Uang::sen($sale->discount);
        $discountAccount = $discount > 0 ? $this->mapping->optional('sales_discount') : null;
        if ($discountAccount !== null) {
            $discountBase = $sale->tax_inclusive && $total > 0 ? Uang::kaliBagi($discount, $net, $total) : $discount;
            $lines->debit($discountAccount, $discountBase, 'Diskon penjualan');
            $lines->credit($this->account('sales'), $net + $discountBase);
        } else {
            $lines->credit($this->account('sales'), $net);
        }
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

    /** Selisih stok opname: kelebihan menambah persediaan, kekurangan menjadi beban selisih. */
    public function forStockOpname(StockOpname $opname): ?Journal
    {
        $opname->loadMissing(['items', 'warehouse', 'finalizedBy', 'user']);
        $prices = $this->costPrices($opname->items->pluck('product_id')->all());
        [$gain, $loss] = [0, 0];

        foreach ($opname->items as $item) {
            $milli = Uang::milli($item->difference);
            $value = Uang::kali(abs($milli), $prices[$item->product_id] ?? 0);
            $milli > 0 ? $gain += $value : $loss += $value;
        }

        $lines = $this->inventoryChange($gain, 'Kelebihan stok hasil opname')->merge($this->inventoryChange(-$loss, 'Kekurangan stok hasil opname'));
        if ($lines->isEmpty()) {
            return null;
        }

        return $this->journals->post($opname->opname_date, "Stok opname {$opname->number} · {$opname->warehouse?->name}", $lines->all(), $opname->finalizedBy ?? $opname->user, $opname, 'approved');
    }

    /** Penyesuaian stok manual (termasuk barang rusak/hilang). */
    public function forStockAdjustment(StockMovement $movement): ?Journal
    {
        $movement->loadMissing(['product', 'warehouse', 'user']);
        $milli = Uang::milli($movement->quantity);
        $value = Uang::kali(abs($milli), Uang::sen($movement->product?->purchase_price ?? 0));

        $label = match ($movement->type) {
            StockMovementType::Damaged => 'Barang rusak',
            StockMovementType::Lost => 'Barang hilang',
            default => $milli > 0 ? 'Penyesuaian stok masuk' : 'Penyesuaian stok keluar',
        };
        $lines = $this->inventoryChange($milli > 0 ? $value : -$value, $label);
        if ($lines->isEmpty()) {
            return null;
        }

        $keterangan = trim("{$label}: {$movement->product?->name} di {$movement->warehouse?->name}".($movement->note ? " ({$movement->note})" : ''));

        return $this->journals->post($movement->created_at ?? now(), $keterangan, $lines->all(), $movement->user, $movement, 'created');
    }

    /**
     * Transfer antar cabang: satu jurnal di cabang asal dan satu di cabang tujuan, lewat
     * Rekening Antar Cabang. Transfer di dalam satu cabang tidak mengubah saldo akun.
     *
     * @return list<Journal>
     */
    public function forStockTransfer(StockTransfer $transfer): array
    {
        $transfer->loadMissing(['items', 'fromWarehouse', 'toWarehouse', 'user']);
        $from = $transfer->fromWarehouse;
        $to = $transfer->toWarehouse;

        if ($from === null || $to === null || (int) $from->branch_id === (int) $to->branch_id) {
            return [];
        }

        $prices = $this->costPrices($transfer->items->pluck('product_id')->all());
        $value = $transfer->items->sum(fn ($item) => Uang::kali(Uang::milli($item->quantity), $prices[$item->product_id] ?? 0));
        if ($value <= 0) {
            return [];
        }

        $description = "Transfer stok {$transfer->number}: {$from->name} ke {$to->name}";
        $out = (new JournalLines)->debit($this->account('interbranch'), $value, "Barang dikirim ke {$to->name}")->credit($this->account('inventory'), $value, 'Persediaan keluar');
        $in = (new JournalLines)->debit($this->account('inventory'), $value, 'Persediaan masuk')->credit($this->account('interbranch'), $value, "Barang diterima dari {$from->name}");

        return [
            $this->journals->post($transfer->transfer_date, $description, $out->all(), $transfer->user, $transfer, 'transfer_out', attributes: ['branch_id' => (int) $from->branch_id]),
            $this->journals->post($transfer->transfer_date, $description, $in->all(), $transfer->user, $transfer, 'transfer_in', attributes: ['branch_id' => (int) $to->branch_id]),
        ];
    }

    /** @return list<Journal> */
    public function forStockTransferVoid(StockTransfer $transfer): array
    {
        $journals = Journal::query()->withoutGlobalScope(BranchScope::class)
            ->where('source_type', $transfer->getMorphClass())
            ->where('source_id', $transfer->getKey())
            ->whereIn('source_event', ['transfer_out', 'transfer_in'])
            ->whereDoesntHave('reversal')
            ->orderBy('id')
            ->get();

        return $journals->map(fn (Journal $journal) => $this->journals->reverse(
            $journal, "Pembatalan transfer stok {$transfer->number} (membalik {$journal->number})", $transfer->voidedBy, $transfer,
            $journal->source_event === 'transfer_out' ? 'void_out' : 'void_in',
        ))->all();
    }

    /** Faktur penjualan / tagihan pembelian manual (faktur saldo awal tidak dijurnal di sini). */
    public function forInvoice(Invoice $invoice): ?Journal
    {
        if ($invoice->isOpening()) {
            return null;
        }

        $invoice->loadMissing(['lines', 'customer', 'supplier']);
        $sales = $invoice->isSales();
        $rows = [];
        foreach ($invoice->lines as $line) {
            $rows[] = ['account_id' => $line->account_id, 'cost_center_id' => $line->cost_center_id, 'description' => $line->description,
                'debit' => $sales ? '0' : (string) $line->amount, 'credit' => $sales ? (string) $line->amount : '0'];
        }
        if (Uang::sen($invoice->tax) > 0) {
            $rows[] = ['account_id' => $this->account($sales ? 'vat_out' : 'vat_in')->id, 'description' => 'PPN',
                'debit' => $sales ? '0' : (string) $invoice->tax, 'credit' => $sales ? (string) $invoice->tax : '0'];
        }
        $withheld = Uang::sen($invoice->withholding_amount ?? 0);
        $rows[] = ['account_id' => $this->account($sales ? 'receivable' : 'payable')->id, 'description' => $invoice->party()?->name,
            'debit' => $sales ? (string) $invoice->total : '0', 'credit' => $sales ? '0' : Uang::keDesimal(Uang::sen($invoice->total) - $withheld)];
        if (! $sales && $withheld > 0) {
            $rows[] = ['account_id' => $this->account($invoice->withholding_kind === 'pph4_2' ? 'pph4_2_payable' : 'pph23_payable')->id,
                'description' => "{$invoice->withholding_name} {$invoice->withholding_rate}%", 'debit' => '0', 'credit' => Uang::keDesimal($withheld)];
        }

        return $this->journals->post($invoice->invoice_date, "{$invoice->type->label()} {$invoice->number} · {$invoice->party()?->name}", $rows, $invoice->postedBy, $invoice, 'posted');
    }

    /** @return list<Journal> */
    public function forInvoiceCancel(Invoice $invoice, ?User $user): array
    {
        return $this->reverseSourceEvents($invoice, ['posted'], "Pembatalan {$invoice->number}", $user);
    }

    /** Pelunasan piutang/hutang dan nota kredit/debit. */
    public function forSettlement(Settlement $settlement): Journal
    {
        $settlement->loadMissing(['cashBankAccount.account', 'counterAccount', 'customer', 'supplier', 'postedBy']);
        $lines = new JournalLines;
        $amount = Uang::sen($settlement->amount);
        $applied = Uang::sen($settlement->deposit_applied);
        $added = Uang::sen($settlement->deposit_added);
        $allocated = $amount + $applied - $added;
        $giro = $settlement->isGiro() ? "Giro {$settlement->giro_number}" : null;

        match ($settlement->type) {
            SettlementType::Receipt => $lines
                ->debit($giro ? $this->account('giro_receivable') : $settlement->cashBankAccount->account, $amount, $giro)
                ->debit($this->account('customer_deposit'), $applied, 'Uang muka dipakai')
                ->credit($this->account('receivable'), $allocated)
                ->credit($this->account('customer_deposit'), $added, 'Uang muka pelanggan'),
            SettlementType::Payment => $lines
                ->debit($this->account('payable'), $allocated)
                ->debit($this->account('supplier_deposit'), $added, 'Uang muka ke supplier')
                ->credit($giro ? $this->account('giro_payable') : $settlement->cashBankAccount->account, $amount, $giro)
                ->credit($this->account('supplier_deposit'), $applied, 'Uang muka dipakai'),
            SettlementType::CreditNote => $lines
                ->debit($settlement->counterAccount, $amount, $settlement->description)
                ->credit($this->account('receivable'), $amount),
            SettlementType::DebitNote => $lines
                ->debit($this->account('payable'), $amount)
                ->credit($settlement->counterAccount, $amount, $settlement->description),
        };

        $party = $settlement->party()?->name;

        return $this->journals->post($settlement->settlement_date, "{$settlement->type->label()} {$settlement->number} · {$party}", $lines->all(), $settlement->postedBy, $settlement, 'posted');
    }

    /** @return list<Journal> */
    public function forSettlementCancel(Settlement $settlement, ?User $user): array
    {
        return $this->reverseSourceEvents($settlement, ['posted', 'giro_cleared'], "Pembatalan {$settlement->number}", $user);
    }

    /** Giro/cek cair: pindah dari akun Giro ke rekening. */
    public function forGiroCleared(Settlement $settlement, ?User $user): Journal
    {
        $settlement->loadMissing('giroCashBankAccount.account');
        $amount = Uang::sen($settlement->amount);
        $rekening = $settlement->giroCashBankAccount->account;
        $description = "Giro {$settlement->giro_number} cair";

        $lines = $settlement->type === SettlementType::Receipt
            ? (new JournalLines)->debit($rekening, $amount, $description)->credit($this->account('giro_receivable'), $amount, $description)
            : (new JournalLines)->debit($this->account('giro_payable'), $amount, $description)->credit($rekening, $amount, $description);

        return $this->journals->post($settlement->giro_cleared_on, "Pencairan giro {$settlement->giro_number} ({$settlement->number})", $lines->all(), $user, $settlement, 'giro_cleared');
    }

    /** Akun wajib untuk peran ini (Pengaturan → Akuntansi). */
    public function account(string $key): Account
    {
        return $this->accounts[$key] ??= $this->mapping->require($key);
    }

    public function paymentAccount(PaymentMethod $method): Account
    {
        return $this->accounts['payment:'.$method->value] ??= $this->mapping->paymentAccount($method);
    }

    /**
     * Balik jurnal sumber dengan kejadian tertentu yang belum dibalik (kejadian pembalik: voided).
     *
     * @param  list<string>  $events
     * @return list<Journal>
     */
    private function reverseSourceEvents(Model $source, array $events, string $description, ?User $user): array
    {
        return Journal::query()->withoutGlobalScope(BranchScope::class)
            ->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
            ->whereIn('source_event', $events)
            ->whereDoesntHave('reversal')
            ->orderBy('id')->get()
            ->map(fn (Journal $journal) => $this->journals->reverse($journal, "{$description} (membalik {$journal->number})", $user, $source,
                $journal->source_event === 'posted' ? 'voided' : "{$journal->source_event}_void"))
            ->all();
    }

    /** Persediaan bertambah (sen > 0) atau berkurang (sen < 0) dengan lawan akun Selisih Persediaan. */
    private function inventoryChange(int $sen, string $description): JournalLines
    {
        $lines = new JournalLines;

        if ($sen > 0) {
            $lines->debit($this->account('inventory'), $sen, $description)->credit($this->account('inventory_variance'), $sen, $description);
        } elseif ($sen < 0) {
            $lines->debit($this->account('inventory_variance'), -$sen, $description)->credit($this->account('inventory'), -$sen, $description);
        }

        return $lines;
    }

    /**
     * Harga pokok produk saat ini (sen) per id produk.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function costPrices(array $productIds): array
    {
        return Product::withTrashed()->whereIn('id', $productIds)->pluck('purchase_price', 'id')
            ->map(fn ($price) => Uang::sen($price))->all();
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
