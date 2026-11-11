<?php

namespace App\Services\Receivables;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseReturnStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SettlementType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Settlement;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Laporan piutang/hutang per pihak dari dokumen yang terlihat di cabang aktif:
 *   openItems() : dokumen yang masih punya sisa (penjualan piutang/faktur pembelian + faktur manual)
 *   aging()     : umur per pihak, berdasarkan tanggal faktur (0–30, 31–60, 61–90, >90 hari) atau
 *                 jatuh tempo (belum jatuh tempo, lewat 1–30, 31–60, 61–90, >90 hari)
 *   card()      : kartu piutang/hutang satu pihak: saldo awal, tagihan, pembayaran, retur, nota, saldo berjalan
 *   schedule()  : jadwal pembayaran hutang per kelompok jatuh tempo
 * Nilai dalam sen.
 */
class PartyLedgerService
{
    public const BUCKETS = [
        'faktur' => ['0–30 hari', '31–60 hari', '61–90 hari', '> 90 hari'],
        'jatuh_tempo' => ['Belum jatuh tempo', 'Lewat 1–30 hari', 'Lewat 31–60 hari', 'Lewat 61–90 hari', 'Lewat > 90 hari'],
    ];

    public function __construct(private readonly OpenDocuments $documents) {}

    /** @return Collection<int, array{key: string, number: string, label: string, party_id: int, party: string, date: string, due_date: ?string, total: int, balance: int, url: ?string}> */
    public function openItems(bool $receivable, ?int $partyId = null): Collection
    {
        $user = auth()->user();
        $items = collect();
        $partyColumn = $receivable ? 'customer_id' : 'supplier_id';

        $documents = $receivable
            ? Sale::query()->outstanding()->with('customer:id,name')
            : Purchase::query()->outstanding()->with('supplier:id,name');
        foreach ($documents->when($partyId, fn ($q) => $q->where($partyColumn, $partyId))->get() as $doc) {
            $items->push([
                'key' => ($receivable ? 'sale' : 'purchase').':'.$doc->id,
                'number' => $doc->number,
                'label' => $receivable ? 'Penjualan' : 'Faktur pembelian',
                'party_id' => (int) $doc->{$partyColumn},
                'party' => ($receivable ? $doc->customer : $doc->supplier)?->name ?? '-',
                'date' => ($receivable ? $doc->sold_at : $doc->purchase_date)->toDateString(),
                'due_date' => $doc->due_date?->toDateString(),
                'total' => Uang::sen($doc->grand_total),
                'balance' => Uang::sen($doc->balance),
                'url' => $this->documents->url($doc, $user),
            ]);
        }

        $invoices = Invoice::query()->ofType($receivable ? InvoiceType::Sales : InvoiceType::Purchase)->outstanding()
            ->with($receivable ? 'customer:id,name' : 'supplier:id,name')
            ->when($partyId, fn ($q) => $q->where($partyColumn, $partyId));
        foreach ($invoices->get() as $doc) {
            $items->push([
                'key' => 'invoice:'.$doc->id,
                'number' => $doc->number,
                'label' => $doc->displayLabel(),
                'party_id' => (int) $doc->{$partyColumn},
                'party' => $doc->party()?->name ?? '-',
                'date' => $doc->invoice_date->toDateString(),
                'due_date' => $doc->due_date?->toDateString(),
                'total' => Uang::sen($doc->total),
                'balance' => Uang::sen($doc->balance),
                'url' => $this->documents->url($doc, $user),
            ]);
        }

        return $items->sortBy(fn (array $i) => [$i['due_date'] ?? $i['date'], $i['number']])->values();
    }

    /**
     * @return array{labels: list<string>, rows: list<array{party_id: int, party: string, buckets: list<int>, total: int, count: int}>, totals: list<int>, total: int}
     */
    public function aging(bool $receivable, string $basis, CarbonImmutable $today): array
    {
        $labels = self::BUCKETS[$basis] ?? self::BUCKETS['faktur'];
        $rows = [];

        foreach ($this->openItems($receivable) as $item) {
            $bucket = $this->bucket($item, $basis, $today);
            $row = &$rows[$item['party_id']];
            $row ??= ['party_id' => $item['party_id'], 'party' => $item['party'], 'buckets' => array_fill(0, count($labels), 0), 'total' => 0, 'count' => 0];
            $row['buckets'][$bucket] += $item['balance'];
            $row['total'] += $item['balance'];
            $row['count']++;
            unset($row);
        }

        $rows = collect($rows)->sortByDesc('total')->values()->all();
        $totals = array_fill(0, count($labels), 0);
        foreach ($rows as $row) {
            foreach ($row['buckets'] as $i => $v) {
                $totals[$i] += $v;
            }
        }

        return ['labels' => $labels, 'rows' => $rows, 'totals' => $totals, 'total' => array_sum($totals)];
    }

    /** Indeks kelompok umur untuk satu dokumen. */
    public function bucket(array $item, string $basis, CarbonImmutable $today): int
    {
        if ($basis === 'jatuh_tempo') {
            $due = CarbonImmutable::parse($item['due_date'] ?? $item['date']);
            $late = (int) $due->diffInDays($today, false);

            return match (true) {
                $late <= 0 => 0,
                $late <= 30 => 1,
                $late <= 60 => 2,
                $late <= 90 => 3,
                default => 4,
            };
        }

        $age = (int) CarbonImmutable::parse($item['date'])->diffInDays($today, false);

        return match (true) {
            $age <= 30 => 0,
            $age <= 60 => 1,
            $age <= 90 => 2,
            default => 3,
        };
    }

    /**
     * Kartu piutang (pelanggan) atau hutang (supplier). "increase" = piutang/hutang bertambah.
     *
     * @return array{opening: int, rows: list<array{date: string, number: string, label: string, increase: int, decrease: int, balance: int, url: ?string}>, increase: int, decrease: int, closing: int, deposit: int, deposits: list<array{date: string, number: string, added: int, applied: int, url: ?string}>}
     */
    public function card(bool $receivable, int $partyId, string $from, string $to): array
    {
        $events = $this->events($receivable, $partyId)->sortBy(fn (array $e) => [$e['date'], $e['order']])->values();
        $opening = $events->filter(fn ($e) => $e['date'] < $from)->sum(fn ($e) => $e['increase'] - $e['decrease']);

        $balance = $opening;
        $rows = [];
        foreach ($events->filter(fn ($e) => $e['date'] >= $from && $e['date'] <= $to) as $event) {
            $balance += $event['increase'] - $event['decrease'];
            $rows[] = array_intersect_key($event, array_flip(['date', 'number', 'label', 'increase', 'decrease', 'url'])) + ['balance' => $balance];
        }

        $deposits = Settlement::query()->where($receivable ? 'customer_id' : 'supplier_id', $partyId)
            ->ofTypes($receivable ? SettlementType::Receipt : SettlementType::Payment)->withStatus(DocumentStatus::Posted)
            ->where(fn ($q) => $q->where('deposit_added', '>', 0)->orWhere('deposit_applied', '>', 0))
            ->orderBy('settlement_date')->orderBy('id')->get();

        $user = auth()->user();

        return [
            'opening' => $opening,
            'rows' => $rows,
            'increase' => array_sum(array_column($rows, 'increase')),
            'decrease' => array_sum(array_column($rows, 'decrease')),
            'closing' => $balance,
            'deposit' => app(SettlementService::class)->depositBalance($receivable, $partyId),
            'deposits' => $deposits->map(fn (Settlement $s) => [
                'date' => $s->settlement_date->toDateString(),
                'number' => $s->number,
                'added' => Uang::sen($s->deposit_added),
                'applied' => Uang::sen($s->deposit_applied),
                'url' => $this->documents->url($s, $user) ?? null,
            ])->all(),
        ];
    }

    /**
     * Jadwal pembayaran hutang: dokumen terbuka dikelompokkan per jatuh tempo.
     *
     * @return list<array{label: string, color: string, items: Collection<int, array<string, mixed>>, total: int}>
     */
    public function schedule(CarbonImmutable $today): array
    {
        $groups = [
            'late' => ['label' => 'Lewat jatuh tempo', 'color' => 'danger'],
            'week' => ['label' => '7 hari ke depan', 'color' => 'warning'],
            'month' => ['label' => '8–30 hari ke depan', 'color' => 'info'],
            'later' => ['label' => 'Lebih dari 30 hari', 'color' => 'neutral'],
            'none' => ['label' => 'Tanpa jatuh tempo', 'color' => 'neutral'],
        ];
        $items = $this->openItems(false)->groupBy(function (array $item) use ($today) {
            if ($item['due_date'] === null) {
                return 'none';
            }
            $days = (int) $today->diffInDays(CarbonImmutable::parse($item['due_date']), false);

            return match (true) {
                $days < 0 => 'late',
                $days <= 7 => 'week',
                $days <= 30 => 'month',
                default => 'later',
            };
        });

        $result = [];
        foreach ($groups as $key => $group) {
            $list = $items->get($key, collect());
            if ($list->isNotEmpty()) {
                $result[] = $group + ['items' => $list->values(), 'total' => $list->sum('balance')];
            }
        }

        return $result;
    }

    /** @return Collection<int, array{date: string, order: int, number: string, label: string, increase: int, decrease: int, url: ?string}> */
    private function events(bool $receivable, int $partyId): Collection
    {
        $user = auth()->user();
        $events = collect();
        $add = function (string $date, int $order, string $number, string $label, int $increase, int $decrease, mixed $doc = null) use (&$events, $user) {
            if ($increase !== 0 || $decrease !== 0) {
                $events->push(['date' => $date, 'order' => $order, 'number' => $number, 'label' => $label, 'increase' => $increase, 'decrease' => $decrease,
                    'url' => $doc ? $this->documents->url($doc, $user) : null]);
            }
        };

        if ($receivable) {
            $docs = Sale::query()->where('customer_id', $partyId)->where('status', PurchaseStatus::Completed->value)
                ->where('payment_method', PaymentMethod::Receivable->value)->get();
            foreach ($docs as $sale) {
                $add($sale->sold_at->toDateString(), 1, $sale->number, 'Penjualan piutang', Uang::sen($sale->grand_total), 0, $sale);
            }
            foreach (SaleReturn::query()->whereIn('sale_id', $docs->modelKeys() ?: [0])->whereNotNull('approved_at')->get() as $return) {
                $add(($return->approved_at ?? $return->return_date)->toDateString(), 3, $return->number, 'Retur penjualan', 0, Uang::sen($return->receivable_reduction), $return);
            }
            $payable = ['sale' => $docs->modelKeys()];
        } else {
            $docs = Purchase::query()->where('supplier_id', $partyId)->where('status', PurchaseStatus::Completed->value)->get();
            foreach ($docs as $purchase) {
                $add($purchase->purchase_date->toDateString(), 1, $purchase->number, 'Faktur pembelian', Uang::sen($purchase->grand_total), 0, $purchase);
            }
            foreach (PurchaseReturn::query()->whereIn('purchase_id', $docs->modelKeys() ?: [0])->where('status', PurchaseReturnStatus::Approved->value)->get() as $return) {
                $add(($return->approved_at ?? $return->return_date)->toDateString(), 3, $return->number, 'Retur pembelian', 0, Uang::sen($return->total) - Uang::sen($return->refund_amount), $return);
            }
            $payable = ['purchase' => $docs->modelKeys()];
        }

        $invoices = Invoice::query()->ofType($receivable ? InvoiceType::Sales : InvoiceType::Purchase)
            ->where($receivable ? 'customer_id' : 'supplier_id', $partyId)->withStatus(DocumentStatus::Posted)->get();
        foreach ($invoices as $invoice) {
            $add($invoice->invoice_date->toDateString(), 1, $invoice->number, $invoice->displayLabel(), Uang::sen($invoice->total), 0, $invoice);
        }
        $payable['invoice'] = $invoices->modelKeys();

        $payments = Payment::query()->whereNull('voided_at')->where(function ($q) use ($payable) {
            foreach ($payable as $type => $ids) {
                $q->orWhere(fn ($w) => $w->where('payable_type', $type)->whereIn('payable_id', $ids ?: [0]));
            }
        })->with('settlement')->get();
        foreach ($payments as $payment) {
            $add($payment->paid_at->toDateString(), 2, $payment->settlement?->number ?? $payment->number, $payment->settlement ? $payment->settlement->type->label() : 'Pembayaran', 0, Uang::sen($payment->amount), $payment->settlement);
        }

        $notes = Settlement::query()->where($receivable ? 'customer_id' : 'supplier_id', $partyId)
            ->ofTypes($receivable ? SettlementType::CreditNote : SettlementType::DebitNote)->withStatus(DocumentStatus::Posted)->get();
        foreach ($notes as $note) {
            $add($note->settlement_date->toDateString(), 2, $note->number, $note->type->label(), 0, Uang::sen($note->amount), $note);
        }

        return $events;
    }
}
