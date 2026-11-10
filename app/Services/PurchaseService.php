<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Events\GoodsReceived;
use App\Events\PurchaseVoided;
use App\Exceptions\AturanBisnisException;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Settings\DocumentNumberService;
use App\Support\DocumentTotals;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Faktur pembelian (catatan hutang ke supplier).
 *
 *   - buat(): pembelian langsung tanpa PO. Stok masuk ke gudang yang dipilih saat itu juga.
 *   - createFromReceipt(): dipanggil GoodsReceiptService. Stok sudah dimasukkan oleh penerimaan,
 *     jadi faktur di sini hanya mencatat nilai dan hutangnya.
 *
 * Angka: subtotal = sebelum diskon baris (sama dengan penjualan),
 * grand_total = subtotal - item_discount - discount + tax + other_cost.
 */
class PurchaseService
{
    public function __construct(
        private readonly StockService $stok,
        private readonly PaymentService $pembayaran,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Simpan pembelian langsung: dokumen, rincian, stok masuk (lewat StockService), harga beli produk,
     * dan pembayaran awal. Semuanya satu transaksi: gagal di mana pun, semuanya dibatalkan.
     *
     * @param array{
     *   supplier_id:int, warehouse_id?:?int, supplier_invoice_number?:?string, purchase_date:string, due_date?:?string,
     *   items:array<int, array{product_id:int, quantity:mixed, unit_price:mixed, discount?:mixed}>,
     *   discount?:mixed, tax?:mixed, other_cost?:mixed, paid_amount?:mixed, payment_method?:?string,
     *   update_cost?:bool, note?:?string
     * } $data
     */
    public function buat(array $data, User $user): Purchase
    {
        $hitung = $this->hitung($data);

        return DB::transaction(function () use ($data, $hitung, $user) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $gudang = ! empty($data['warehouse_id'])
                ? Warehouse::query()->active()->findOrFail($data['warehouse_id'])
                : $this->stok->defaultWarehouse();

            $ids = array_keys($hitung['baris']);
            sort($ids); // urutan tetap saat mengunci produk: mencegah deadlock antar pembelian
            $produk = Product::with('unit:id,name')->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');

            if ($produk->count() !== count($ids)) {
                throw new AturanBisnisException('Ada produk yang tidak ditemukan atau sudah dihapus. Muat ulang halaman.');
            }

            $pembelian = $this->simpanFaktur($supplier, $gudang, $data, $hitung, $user);
            $this->simpanBaris($pembelian, $produk, $hitung['baris'], $data['update_cost'] ?? true);

            foreach ($ids as $id) {
                $this->stok->catat(
                    $produk[$id],
                    StockMovementType::Purchase,
                    Uang::milliKeDesimal($hitung['baris'][$id]['milli']),
                    $pembelian,
                    "Pembelian {$pembelian->number} dari {$supplier->name}",
                    $user,
                    $gudang,
                );
            }

            if ($hitung['dibayar'] > 0) {
                $this->pembayaran->bayarPembelian(
                    $pembelian,
                    $hitung['dibayar'],
                    PaymentMethod::from($data['payment_method']),
                    'Pembayaran saat pembelian',
                    $user,
                    $data['purchase_date'],
                );
            }

            GoodsReceived::dispatch($pembelian, null);

            return $pembelian->load(['supplier', 'items', 'payments']);
        });
    }

    /**
     * Faktur/hutang untuk satu penerimaan barang. Harus dipanggil di dalam transaksi GoodsReceiptService.
     * Angka sudah dihitung (dalam sen/milli) oleh penerimaan, termasuk bagian diskon, pajak, dan biaya PO.
     *
     * @param  array<int, array{milli:int, harga:int, diskon:int}>  $baris  product_id => angka
     * @param  array{diskon:int, pajak:int, biaya:int, due_date:string, supplier_invoice_number:?string}  $kepala
     */
    public function createFromReceipt(GoodsReceipt $receipt, array $baris, array $kepala, User $user, bool $updateCost): Purchase
    {
        $hitung = DocumentTotals::totals($baris, $kepala['diskon'], $kepala['pajak'], $kepala['biaya']);
        $receipt->loadMissing(['supplier', 'warehouse', 'purchaseOrder']);

        $pembelian = $this->simpanFaktur($receipt->supplier, $receipt->warehouse, [
            'purchase_order_id' => $receipt->purchase_order_id,
            'supplier_invoice_number' => $kepala['supplier_invoice_number'],
            'purchase_date' => $receipt->received_date->toDateString(),
            'due_date' => $kepala['due_date'],
            'note' => "Penerimaan {$receipt->number} atas {$receipt->purchaseOrder->number}",
        ], $hitung, $user);

        $produk = Product::withTrashed()->with('unit:id,name')->whereIn('id', array_keys($baris))->get()->keyBy('id');
        $this->simpanBaris($pembelian, $produk, $hitung['baris'], $updateCost);

        return $pembelian;
    }

    /**
     * Batalkan pembelian langsung (void): stok dikembalikan, pembayaran ditandai batal, dokumen tetap tersimpan.
     * Ditolak bila barangnya sudah terjual/keluar sehingga stok tidak cukup; gunakan Retur Pembelian.
     * Faktur dari penerimaan barang dibatalkan lewat GoodsReceiptService::void().
     */
    public function batalkan(Purchase $pembelian, string $alasan, User $user): Purchase
    {
        $alasan = $this->alasan($alasan);

        return DB::transaction(function () use ($pembelian, $alasan, $user) {
            $terkunci = Purchase::whereKey($pembelian->getKey())->lockForUpdate()->with(['items', 'goodsReceipt'])->firstOrFail();
            $this->ensureVoidable($terkunci);

            if ($terkunci->fromReceipt()) {
                throw new AturanBisnisException(
                    "Faktur {$terkunci->number} berasal dari penerimaan barang {$terkunci->goodsReceipt?->number}. Batalkan lewat halaman penerimaan barang."
                );
            }

            $gudang = Warehouse::withTrashed()->find($terkunci->warehouse_id) ?? $this->stok->defaultWarehouse();

            try {
                foreach ($terkunci->items->sortBy('product_id') as $item) {
                    $this->stok->catat(
                        Product::withTrashed()->findOrFail($item->product_id),
                        StockMovementType::Purchase,
                        '-'.$item->quantity,
                        $terkunci,
                        "Pembatalan pembelian {$terkunci->number}: {$alasan}",
                        $user,
                        $gudang,
                    );
                }
            } catch (AturanBisnisException $e) {
                throw new AturanBisnisException(
                    "Pembelian {$terkunci->number} tidak dapat dibatalkan karena sebagian barangnya sudah keluar. "
                    .$e->getMessage().' Gunakan Retur Pembelian untuk barang yang sudah terjual.'
                );
            }

            $this->markVoided($terkunci, $alasan, $user);

            // Harga beli produk tidak dikembalikan otomatis; ubah manual di master produk bila perlu.
            return $terkunci;
        });
    }

    /**
     * Tolak pembatalan faktur yang sudah batal atau sudah punya retur disetujui
     * (retur sudah mengurangi stok dan hutang; batalkan ganda akan merusak angka).
     */
    public function ensureVoidable(Purchase $locked): void
    {
        if ($locked->status === PurchaseStatus::Voided) {
            throw new AturanBisnisException('Pembelian ini sudah dibatalkan.');
        }

        if ($locked->returns()->where('status', PurchaseReturnStatus::Approved->value)->exists()) {
            throw new AturanBisnisException("Faktur {$locked->number} sudah punya retur yang disetujui, sehingga tidak dapat dibatalkan.");
        }
    }

    /** Tandai faktur (yang sudah dikunci) batal beserta pembayarannya, catat log, dan kirim event. */
    public function markVoided(Purchase $locked, string $alasan, User $user): void
    {
        $sekarang = now();

        foreach ($locked->payments()->whereNull('voided_at')->get() as $pembayaran) {
            $pembayaran->forceFill(['voided_at' => $sekarang, 'voided_by' => $user->getKey(), 'void_reason' => "Pembelian dibatalkan: {$alasan}"])->save();
        }

        // Draft retur atas faktur ini tidak bisa disetujui lagi.
        $locked->returns()->where('status', PurchaseReturnStatus::Draft->value)->get()->each->delete();

        $locked->forceFill([
            'status' => PurchaseStatus::Voided,
            'voided_at' => $sekarang,
            'voided_by' => $user->getKey(),
            'void_reason' => $alasan,
        ])->save();

        activity('pembelian')->performedOn($locked)->causedBy($user)->event('voided')
            ->withProperties(['alasan' => $alasan, 'total' => $locked->grand_total])
            ->log("Pembelian {$locked->number} dibatalkan");

        PurchaseVoided::dispatch($locked);
    }

    public function alasan(string $alasan): string
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan pembatalan minimal 5 karakter.']);
        }

        return $alasan;
    }

    /**
     * @param  array{purchase_order_id?:?int, supplier_invoice_number?:?string, purchase_date:string, due_date?:?string, note?:?string}  $data
     * @param  array{subtotal:int, diskonBaris:int, diskon:int, pajak:int, biaya:int, total:int}  $hitung
     */
    private function simpanFaktur(Supplier $supplier, Warehouse $gudang, array $data, array $hitung, User $user): Purchase
    {
        $pembelian = new Purchase([
            'number' => $this->numbers->next(DocumentType::Purchase, $data['purchase_date']),
            'warehouse_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            'purchase_date' => $data['purchase_date'],
            'due_date' => $data['due_date'] ?? null,
            'subtotal' => Uang::keDesimal($hitung['subtotal']),
            'item_discount' => Uang::keDesimal($hitung['diskonBaris']),
            'discount' => Uang::keDesimal($hitung['diskon']),
            'tax' => Uang::keDesimal($hitung['pajak']),
            'other_cost' => Uang::keDesimal($hitung['biaya']),
            'grand_total' => Uang::keDesimal($hitung['total']),
            'note' => $data['note'] ?? null,
            'user_id' => $user->getKey(),
        ]);

        $pembelian->forceFill([
            'paid_amount' => '0.00',
            'balance' => Uang::keDesimal($hitung['total']),
            'payment_status' => PaymentStatus::dari(0, $hitung['total']),
            'status' => PurchaseStatus::Completed,
        ])->save();

        return $pembelian;
    }

    /**
     * Rincian faktur, plus harga beli produk mengikuti pembelian terbaru (dasar harga modal penjualan
     * berikutnya): harga bersih per unit setelah diskon baris. Histori lama tidak berubah.
     *
     * @param  Collection<int, Product>  $produk
     * @param  array<int, array{milli:int, harga:int, diskon:int, total:int}>  $baris
     */
    private function simpanBaris(Purchase $pembelian, Collection $produk, array $baris, bool $updateCost): void
    {
        ksort($baris);

        foreach ($baris as $id => $b) {
            $p = $produk[$id];

            $pembelian->items()->create([
                'product_id' => $p->getKey(),
                'product_name' => $p->name,
                'sku' => $p->sku,
                'unit_name' => $p->unit?->name ?? '-',
                'quantity' => Uang::milliKeDesimal($b['milli']),
                'unit_price' => Uang::keDesimal($b['harga']),
                'discount' => Uang::keDesimal($b['diskon']),
                'line_total' => Uang::keDesimal($b['total']),
            ]);

            if ($updateCost) {
                $bersih = Uang::perUnit($b['total'], $b['milli']);
                if ($bersih > 0 && $bersih !== Uang::sen($p->purchase_price)) {
                    $p->purchase_price = Uang::keDesimal($bersih);
                    $p->save();
                }
            }
        }
    }

    /**
     * Hitung seluruh angka pembelian langsung dengan bilangan bulat dan kumpulkan SEMUA kesalahan sekaligus.
     *
     * @return array{baris:array<int,array<string,int>>, subtotal:int, diskonBaris:int, diskon:int, pajak:int, biaya:int, total:int, dibayar:int}
     */
    private function hitung(array $data): array
    {
        [$baris, $errors] = DocumentTotals::lines($data['items'] ?? []);
        $hitung = DocumentTotals::totals($baris, Uang::sen($data['discount'] ?? 0), Uang::sen($data['tax'] ?? 0), Uang::sen($data['other_cost'] ?? 0));
        $errors += DocumentTotals::headerErrors($hitung);

        $dibayar = Uang::sen($data['paid_amount'] ?? 0);

        if ($dibayar > $hitung['total']) {
            $errors['paid_amount'] = 'Pembayaran melebihi total pembelian.';
        }

        if ($hitung['total'] - $dibayar > 0 && empty($data['due_date'])) {
            $errors['due_date'] = 'Jatuh tempo wajib diisi bila masih ada sisa hutang.';
        }

        if ($dibayar > 0 && empty($data['payment_method'])) {
            $errors['payment_method'] = 'Pilih metode pembayaran.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $hitung + ['dibayar' => $dibayar];
    }
}
