<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Events\GoodsReceived;
use App\Exceptions\AturanBisnisException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\PurchaseService;
use App\Services\Settings\DocumentNumberService;
use App\Services\StockService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan barang dari purchase order yang sudah disetujui (boleh sebagian). Satu transaksi:
 *   1. stok masuk ke gudang tujuan lewat StockService (kartu stok merujuk dokumen penerimaan),
 *   2. jumlah diterima di baris PO bertambah dan status PO dihitung ulang,
 *   3. satu faktur (purchases) dibuat berisi hutang senilai barang yang diterima,
 *   4. event GoodsReceived dikirim setelah transaksi berhasil (untuk Akuntansi).
 *
 * Nilai faktur: harga PO x jumlah diterima. Diskon baris PO dibagi menurut jumlah; diskon, pajak,
 * dan biaya lain PO dibagi menurut nilai barang. Sisa pembulatan masuk ke penerimaan yang
 * menyelesaikan baris/PO, sehingga jumlah semua faktur sama dengan PO.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly StockService $stok,
        private readonly PurchaseService $purchases,
        private readonly PurchaseOrderService $orders,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @param array{
     *   warehouse_id:int, received_date:string, due_date:string, delivery_note_number?:?string,
     *   supplier_invoice_number?:?string, note?:?string, update_cost?:bool,
     *   items:array<int|string, mixed>
     * } $data items = purchase_order_item_id => jumlah diterima (0/kosong = tidak diterima)
     */
    public function create(PurchaseOrder $order, array $data, User $user): GoodsReceipt
    {
        app(FiscalPeriodService::class)->ensureOpen($data['received_date']);

        return DB::transaction(function () use ($order, $data, $user) {
            $po = $this->orders->lock($order);

            if (! $po->status->canReceive()) {
                throw new AturanBisnisException("Purchase order {$po->number} berstatus {$po->status->label()}. Barang hanya bisa diterima dari PO yang sudah disetujui.");
            }

            $gudang = Warehouse::query()->active()->findOrFail($data['warehouse_id']);
            $poItems = $po->items()->orderBy('product_id')->get()->keyBy('id');
            $diterima = $this->receivedQuantities($poItems, $data['items'] ?? []);

            $receipt = GoodsReceipt::create([
                'number' => $this->numbers->next(DocumentType::GoodsReceipt, $data['received_date']),
                'purchase_order_id' => $po->getKey(),
                'supplier_id' => $po->supplier_id,
                'warehouse_id' => $gudang->getKey(),
                'received_date' => $data['received_date'],
                'delivery_note_number' => $data['delivery_note_number'] ?? null,
                'note' => $data['note'] ?? null,
                'user_id' => $user->getKey(),
            ]);
            $receipt->forceFill(['status' => PurchaseStatus::Completed])->save();

            $baris = [];
            $nilaiBersih = 0;

            foreach ($poItems as $itemId => $poItem) {
                if (! isset($diterima[$itemId])) {
                    continue;
                }

                $milli = $diterima[$itemId];
                $harga = Uang::sen($poItem->unit_price);
                $diskon = $this->lineDiscount($poItem, $milli);
                $total = Uang::kali($milli, $harga) - $diskon;
                $jumlah = Uang::milliKeDesimal($milli);

                $receipt->items()->create([
                    'purchase_order_item_id' => $poItem->getKey(),
                    'product_id' => $poItem->product_id,
                    'product_name' => $poItem->product_name,
                    'sku' => $poItem->sku,
                    'unit_name' => $poItem->unit_name,
                    'quantity' => $jumlah,
                    'unit_price' => Uang::keDesimal($harga),
                    'discount' => Uang::keDesimal($diskon),
                    'line_total' => Uang::keDesimal($total),
                ]);

                $poItem->forceFill(['received_quantity' => Uang::milliKeDesimal(Uang::milli($poItem->received_quantity) + $milli)])->save();

                $this->stok->catat(
                    Product::withTrashed()->findOrFail($poItem->product_id),
                    StockMovementType::Purchase,
                    $jumlah,
                    $receipt,
                    "Penerimaan {$receipt->number} atas {$po->number}",
                    $user,
                    $gudang,
                );

                $baris[$poItem->product_id] = ['milli' => $milli, 'harga' => $harga, 'diskon' => $diskon];
                $nilaiBersih += $total;
            }

            $selesai = $poItems->every(fn ($i) => $i->remainingQuantity() <= 0);

            $pembelian = $this->purchases->createFromReceipt($receipt, $baris, [
                'diskon' => $this->headerShare($po, 'discount', $nilaiBersih, $selesai),
                'pajak' => $this->headerShare($po, 'tax', $nilaiBersih, $selesai),
                'biaya' => $this->headerShare($po, 'other_cost', $nilaiBersih, $selesai),
                'due_date' => $data['due_date'],
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            ], $user, $data['update_cost'] ?? true);

            $receipt->forceFill(['purchase_id' => $pembelian->getKey()])->save();
            $this->orders->refreshStatus($po);

            activity('pembelian')->performedOn($receipt)->causedBy($user)->event('created')
                ->withProperties(['po' => $po->number, 'faktur' => $pembelian->number, 'gudang' => $gudang->name, 'nilai' => $pembelian->grand_total])
                ->log("Barang {$po->number} diterima ({$receipt->number})");

            GoodsReceived::dispatch($pembelian, $receipt);

            return $receipt->load(['items', 'purchase']);
        });
    }

    /**
     * Batalkan penerimaan: stok keluar lagi dari gudang tujuan, jumlah diterima di PO berkurang,
     * dan fakturnya dibatalkan (beserta pembayarannya). Ditolak bila barang sudah keluar
     * dari gudang atau fakturnya sudah punya retur yang disetujui.
     */
    public function void(GoodsReceipt $receipt, string $alasan, User $user): GoodsReceipt
    {
        $alasan = $this->purchases->alasan($alasan);
        app(FiscalPeriodService::class)->ensureOpen(now());

        return DB::transaction(function () use ($receipt, $alasan, $user) {
            // Urutan kunci sama dengan create(): PO dulu, lalu dokumen lain, lalu stok.
            $po = $this->orders->lock($receipt->purchaseOrder);
            $terkunci = GoodsReceipt::whereKey($receipt->getKey())->lockForUpdate()->with(['items', 'warehouse'])->firstOrFail();

            if ($terkunci->dibatalkan()) {
                throw new AturanBisnisException('Penerimaan ini sudah dibatalkan.');
            }

            $pembelian = Purchase::whereKey($terkunci->purchase_id)->lockForUpdate()->firstOrFail();
            $this->purchases->ensureVoidable($pembelian);

            $catatan = "Pembatalan penerimaan {$terkunci->number}: {$alasan}";

            try {
                foreach ($terkunci->items->sortBy('product_id') as $item) {
                    $this->stok->catat(
                        Product::withTrashed()->findOrFail($item->product_id),
                        StockMovementType::Purchase,
                        '-'.$item->quantity,
                        $terkunci,
                        $catatan,
                        $user,
                        $terkunci->warehouse,
                    );
                }
            } catch (AturanBisnisException $e) {
                throw new AturanBisnisException(
                    "Penerimaan {$terkunci->number} tidak dapat dibatalkan karena sebagian barangnya sudah keluar dari {$terkunci->warehouse->name}. "
                    .$e->getMessage().' Gunakan Retur Pembelian untuk barang yang masih ada.'
                );
            }

            foreach ($terkunci->items as $item) {
                $poItem = $po->items()->whereKey($item->purchase_order_item_id)->firstOrFail();
                $poItem->forceFill(['received_quantity' => Uang::milliKeDesimal(max(0, Uang::milli($poItem->received_quantity) - Uang::milli($item->quantity)))])->save();
            }

            $terkunci->forceFill([
                'status' => PurchaseStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $user->getKey(),
                'void_reason' => $alasan,
            ])->save();

            $this->purchases->markVoided($pembelian, "Penerimaan {$terkunci->number} dibatalkan: {$alasan}", $user);
            $this->orders->refreshStatus($po);

            activity('pembelian')->performedOn($terkunci)->causedBy($user)->event('voided')
                ->withProperties(['alasan' => $alasan, 'faktur' => $pembelian->number])
                ->log("Penerimaan barang {$terkunci->number} dibatalkan");

            return $terkunci;
        });
    }

    /**
     * Jumlah diterima per baris PO (milli). Kesalahan dikumpulkan per baris: items.{id}.
     *
     * @param  Collection<int, PurchaseOrderItem>  $poItems
     * @param  array<int|string, mixed>  $input
     * @return array<int, int>
     */
    private function receivedQuantities(Collection $poItems, array $input): array
    {
        $hasil = [];
        $errors = [];

        foreach ($input as $itemId => $jumlah) {
            $milli = Uang::milli($jumlah ?? 0);

            if ($milli <= 0) {
                continue;
            }

            $poItem = $poItems[(int) $itemId] ?? null;
            if ($poItem === null) {
                throw new AturanBisnisException('Ada barang yang bukan bagian dari purchase order ini. Muat ulang halaman.');
            }

            $sisa = Uang::milli($poItem->quantity) - Uang::milli($poItem->received_quantity);
            if ($milli > $sisa) {
                $errors["items.{$itemId}"] = sprintf('%s: jumlah diterima melebihi sisa pesanan (%s %s).',
                    $poItem->product_name, Format::jumlah(Uang::milliKeDesimal($sisa)), $poItem->unit_name);

                continue;
            }

            $hasil[$poItem->getKey()] = $milli;
        }

        if ($hasil === [] && $errors === []) {
            $errors['items'] = 'Isi jumlah diterima minimal untuk satu barang.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $hasil;
    }

    /** Bagian diskon baris PO untuk jumlah ini. Penerimaan yang menyelesaikan baris mendapat sisanya. */
    private function lineDiscount(PurchaseOrderItem $poItem, int $milli): int
    {
        $diskonBaris = Uang::sen($poItem->discount);
        $dipesan = Uang::milli($poItem->quantity);

        if ($diskonBaris === 0 || $dipesan === 0) {
            return 0;
        }

        if (Uang::milli($poItem->received_quantity) + $milli >= $dipesan) {
            $sudah = Uang::sen(GoodsReceiptItem::query()
                ->where('purchase_order_item_id', $poItem->getKey())
                ->whereHas('receipt', fn ($q) => $q->where('status', PurchaseStatus::Completed->value))
                ->sum('discount'));

            return max(0, $diskonBaris - $sudah);
        }

        return (int) round($diskonBaris * $milli / $dipesan);
    }

    /**
     * Bagian diskon/pajak/biaya lain PO untuk penerimaan ini, sebanding nilai barangnya.
     * Penerimaan yang menyelesaikan PO mendapat sisanya.
     */
    private function headerShare(PurchaseOrder $po, string $column, int $nilaiBersih, bool $selesai): int
    {
        $total = Uang::sen($po->getAttribute($column));

        if ($total === 0) {
            return 0;
        }

        $sudah = Uang::sen(Purchase::query()->where('purchase_order_id', $po->getKey())->selesai()->sum($column));
        $sisa = max(0, $total - $sudah);

        if ($selesai) {
            return $sisa;
        }

        $nilaiPo = Uang::sen($po->subtotal) - Uang::sen($po->item_discount);
        if ($nilaiPo <= 0) {
            return 0;
        }

        return min($sisa, (int) round($total * $nilaiBersih / $nilaiPo));
    }
}
