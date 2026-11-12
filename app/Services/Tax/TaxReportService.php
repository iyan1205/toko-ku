<?php

namespace App\Services\Tax;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\PurchaseStatus;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\LedgerService;
use App\Support\DocumentLink;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Laporan pajak.
 *
 *   rekap PPN per masa : dari jurnal posted pada akun PPN Keluaran dan PPN Masukan, sehingga retur,
 *                        pembatalan, dan koreksi manual ikut terhitung. Selisih positif = kurang bayar.
 *   daftar faktur      : dokumen asli ber-PPN di masa itu (penjualan kasir, faktur manual, pembelian,
 *                        tagihan manual) untuk dicocokkan dan diekspor ke sistem pajak.
 *   bukti potong PPh   : tagihan pembelian dengan PPh 23 / PPh 4(2) yang dipotong.
 */
class TaxReportService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountMappingService $mapping,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  cabang
     * @return list<array{month: int, period: string, output: int, input: int, net: int}>
     */
    public function ppnRecap(int $year, array $filters = []): array
    {
        $out = $this->mapping->account('vat_out');
        $in = $this->mapping->account('vat_in');
        $rows = [];

        for ($m = 1; $m <= 12; $m++) {
            $from = CarbonImmutable::create($year, $m, 1);
            $to = $from->endOfMonth()->toDateString();
            $o = $out ? $this->ledger->periodTotals($out->id, $from->toDateString(), $to, $filters) : ['debit' => 0, 'credit' => 0];
            $i = $in ? $this->ledger->periodTotals($in->id, $from->toDateString(), $to, $filters) : ['debit' => 0, 'credit' => 0];
            $output = $o['credit'] - $o['debit'];
            $input = $i['debit'] - $i['credit'];
            $rows[] = ['month' => $m, 'period' => $from->format('Y-m'), 'output' => $output, 'input' => $input, 'net' => $output - $input];
        }

        return $rows;
    }

    /**
     * Dokumen ber-PPN satu masa. $direction: keluaran | masukan. Nilai dalam sen.
     *
     * @return Collection<int, array{date: CarbonImmutable, number: string, type: string, party_tax_id: ?string, party_name: string, party_address: ?string, dpp: int, rate: string, ppn: int, reference: ?string, url: ?string}>
     */
    public function documents(string $period, string $direction): Collection
    {
        ['from' => $from, 'until' => $until] = LedgerService::bounds($period.'-01', CarbonImmutable::parse($period.'-01')->endOfMonth()->toDateString());
        $user = auth()->user();
        $rows = collect();

        if ($direction === 'keluaran') {
            foreach (Sale::query()->with('customer')->where('status', PurchaseStatus::Completed->value)->where('tax', '>', 0)
                ->where('sold_at', '>=', $from)->where('sold_at', '<', $until)->orderBy('sold_at')->get() as $sale) {
                $rows->push($this->row(CarbonImmutable::parse($sale->sold_at), $sale->number, 'Penjualan kasir', $sale->customer, Uang::sen($sale->grand_total) - Uang::sen($sale->tax), (string) $sale->tax_rate, Uang::sen($sale->tax), null, DocumentLink::url($sale, $user)));
            }
            $type = InvoiceType::Sales;
        } else {
            foreach (Purchase::query()->with('supplier')->where('status', PurchaseStatus::Completed->value)->where('tax', '>', 0)
                ->where('purchase_date', '>=', $from)->where('purchase_date', '<', $until)->orderBy('purchase_date')->get() as $purchase) {
                $dpp = Uang::sen($purchase->grand_total) - Uang::sen($purchase->tax);
                $rows->push($this->row(CarbonImmutable::parse($purchase->purchase_date), $purchase->number, 'Faktur pembelian', $purchase->supplier, $dpp,
                    $dpp > 0 ? number_format(Uang::sen($purchase->tax) / $dpp * 100, 2, '.', '') : '0', Uang::sen($purchase->tax), $purchase->supplier_invoice_number, DocumentLink::url($purchase, $user)));
            }
            $type = InvoiceType::Purchase;
        }

        foreach (Invoice::query()->ofType($type)->withStatus(DocumentStatus::Posted)->where('kind', 'manual')->where('tax', '>', 0)
            ->where('invoice_date', '>=', $from)->where('invoice_date', '<', $until)->with(['customer', 'supplier'])->orderBy('invoice_date')->get() as $invoice) {
            $rows->push($this->row(CarbonImmutable::parse($invoice->invoice_date), $invoice->number, $invoice->type->label(), $invoice->party(), Uang::sen($invoice->subtotal), (string) $invoice->tax_rate, Uang::sen($invoice->tax), $invoice->reference, DocumentLink::url($invoice, $user)));
        }

        return $rows->sortBy(fn ($r) => [$r['date']->timestamp, $r['number']])->values();
    }

    /** @return Collection<int, Invoice> tagihan pembelian dengan PPh dipotong di masa itu */
    public function withholdings(string $period, ?string $kind = null): Collection
    {
        ['from' => $from, 'until' => $until] = LedgerService::bounds($period.'-01', CarbonImmutable::parse($period.'-01')->endOfMonth()->toDateString());

        return Invoice::query()->ofType(InvoiceType::Purchase)->withStatus(DocumentStatus::Posted)->where('withholding_amount', '>', 0)
            ->where('invoice_date', '>=', $from)->where('invoice_date', '<', $until)
            ->when($kind, fn ($q, $v) => $q->where('withholding_kind', $v))
            ->with('supplier')->orderBy('invoice_date')->orderBy('id')->get();
    }

    /** @return array{date: CarbonImmutable, number: string, type: string, party_tax_id: ?string, party_name: string, party_address: ?string, dpp: int, rate: string, ppn: int, reference: ?string, url: ?string} */
    private function row(CarbonImmutable $date, string $number, string $type, mixed $party, int $dpp, string $rate, int $ppn, ?string $reference, ?string $url): array
    {
        return [
            'date' => $date,
            'number' => $number,
            'type' => $type,
            'party_tax_id' => $party?->tax_id ?? $party?->npwp ?? null,
            'party_name' => $party?->name ?? 'Umum',
            'party_address' => $party?->address,
            'dpp' => $dpp,
            'rate' => rtrim(rtrim($rate, '0'), '.') ?: '0',
            'ppn' => $ppn,
            'reference' => $reference,
            'url' => $url,
        ];
    }
}
