<?php

namespace App\Services\Receivables;

use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\PaymentService;
use App\Support\DocumentLink;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Dokumen yang bisa dilunasi:
 *   piutang : penjualan kasir dengan sisa piutang (sale) + faktur penjualan manual/saldo awal (invoice)
 *   hutang  : faktur pembelian (purchase) + tagihan pembelian manual/saldo awal (invoice)
 *
 * Kunci dokumen = "{morph}:{id}", misal "sale:12". Nilai dalam sen.
 */
class OpenDocuments
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = ['sale' => Sale::class, 'purchase' => Purchase::class, 'invoice' => Invoice::class];

    /**
     * Dokumen terbuka satu pihak di cabang yang terlihat, terlama dulu.
     *
     * @return Collection<int, array{key: string, type: string, id: int, number: string, label: string, date: string, due_date: ?string, total: string, balance: string, url: ?string}>
     */
    public function open(bool $receivable, int $partyId): Collection
    {
        $user = auth()->user();
        $rows = collect();

        if ($receivable) {
            foreach (Sale::query()->outstanding()->where('customer_id', $partyId)->orderBy('sold_at')->get() as $sale) {
                $rows->push($this->row('sale', $sale, 'Penjualan', $sale->sold_at->toDateString(), $sale->due_date?->toDateString(), $sale->grand_total, $user));
            }
            $invoices = Invoice::query()->ofType(InvoiceType::Sales)->outstanding()->where('customer_id', $partyId);
        } else {
            foreach (Purchase::query()->outstanding()->where('supplier_id', $partyId)->orderBy('purchase_date')->get() as $purchase) {
                $rows->push($this->row('purchase', $purchase, 'Faktur pembelian', $purchase->purchase_date->toDateString(), $purchase->due_date?->toDateString(), $purchase->grand_total, $user));
            }
            $invoices = Invoice::query()->ofType(InvoiceType::Purchase)->outstanding()->where('supplier_id', $partyId);
        }

        foreach ($invoices->orderBy('invoice_date')->get() as $invoice) {
            $rows->push($this->row('invoice', $invoice, $invoice->displayLabel(), $invoice->invoice_date->toDateString(), $invoice->due_date?->toDateString(), $invoice->total, $user));
        }

        return $rows->sortBy(fn (array $r) => [$r['due_date'] ?? $r['date'], $r['date']])->values();
    }

    /** Kunci dokumen sampai transaksi selesai, dan periksa sisi (piutang/hutang) serta pihaknya. */
    public function lock(string $type, int $id, bool $receivable, int $partyId): Model
    {
        $class = self::TYPES[$type] ?? throw new AturanBisnisException('Jenis dokumen tidak dikenal.');
        $document = $class::query()->withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();

        if ($document === null || $this->isReceivable($document) !== $receivable || $this->partyId($document) !== $partyId) {
            throw new AturanBisnisException('Dokumen yang dipilih bukan milik '.($receivable ? 'pelanggan' : 'supplier').' ini.');
        }

        return $document;
    }

    public function isReceivable(Model $document): bool
    {
        return $document instanceof Sale || ($document instanceof Invoice && $document->isSales());
    }

    public function partyId(Model $document): ?int
    {
        return $this->isReceivable($document) ? (int) $document->customer_id : (int) $document->supplier_id;
    }

    public function balance(Model $document): int
    {
        return Uang::sen($document->balance);
    }

    /** Dokumen masih bisa dilunasi: transaksi selesai (penjualan/pembelian) atau faktur posted. */
    public function isPayable(Model $document): bool
    {
        return $document instanceof Invoice ? $document->isPosted() : $document->status?->value === 'completed';
    }

    /** Kurangi sisa dokumen dengan nota kredit/debit. */
    public function applyCredit(Model $document, int $sen): void
    {
        if ($sen > $this->balance($document)) {
            throw new AturanBisnisException("Nilai nota melebihi sisa {$document->number}.");
        }

        $this->changeCredit($document, $sen);
    }

    /** Kembalikan sisa dokumen karena nota dibatalkan. */
    public function revertCredit(Model $document, int $sen): void
    {
        $this->changeCredit($document, -$sen);
    }

    private function changeCredit(Model $document, int $sen): void
    {
        $credited = Uang::sen($document->credited_amount ?? 0) + $sen;
        $balance = $this->balance($document) - $sen;

        $document->forceFill([
            'credited_amount' => Uang::keDesimal($credited),
            'balance' => Uang::keDesimal($balance),
            'payment_status' => PaymentStatus::dari(Uang::sen($document->paid_amount) + $credited, $balance),
        ])->save();

        $piutang = ($document instanceof Sale && $document->payment_method === PaymentMethod::Receivable)
            || ($document instanceof Invoice && $document->isSales());
        if ($piutang) {
            app(PaymentService::class)->ubahPiutangPelanggan($document->customer_id, -$sen);
        }
    }

    /** @return array{key: string, type: string, id: int, number: string, label: string, date: string, due_date: ?string, total: string, balance: string, url: ?string} */
    private function row(string $type, Model $document, string $label, string $date, ?string $dueDate, mixed $total, mixed $user): array
    {
        return [
            'key' => "{$type}:{$document->getKey()}",
            'type' => $type,
            'id' => (int) $document->getKey(),
            'number' => $document->number,
            'label' => $label,
            'date' => $date,
            'due_date' => $dueDate,
            'total' => (string) $total,
            'balance' => (string) $document->balance,
            'url' => $this->url($document, $user),
        ];
    }

    public function url(Model $document, mixed $user): ?string
    {
        if ($document instanceof Invoice) {
            return $user?->can($document->type->permission('view')) ? route($document->type->routePrefix().'.show', $document) : null;
        }

        return DocumentLink::url($document, $user);
    }
}
