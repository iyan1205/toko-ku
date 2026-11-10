<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Approval\ApprovalService;
use App\Services\Settings\DocumentNumberService;
use App\Support\DocumentTotals;
use App\Support\Uang;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase order: draft → disetujui → diterima sebagian → selesai, atau dibatalkan.
 *
 *   - Draft bisa diubah dan dihapus. PO tidak mengubah stok maupun hutang.
 *   - Disetujui (izin purchasing.orders.approve): barang bisa diterima lewat Penerimaan Barang.
 *   - Bila ada alur persetujuan yang cocok (misal total > Rp 5.000.000), PO tidak bisa disetujui
 *     langsung: diajukan (submit) lalu disetujui per tahap lewat ApprovalService.
 *   - Dibatalkan: hanya bila belum ada barang yang diterima.
 *   - Ditutup: PO yang sudah diterima sebagian dan sisanya tidak akan dikirim supplier.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param array{
     *   supplier_id:int, warehouse_id:int, order_date:string, expected_date?:?string, note?:?string,
     *   items:array<int, array{product_id:int, quantity:mixed, unit_price:mixed, discount?:mixed}>,
     *   discount?:mixed, tax?:mixed, other_cost?:mixed
     * } $data
     */
    public function create(array $data, User $user, bool $approve = false, bool $submit = false): PurchaseOrder
    {
        $hitung = $this->hitung($data);

        return DB::transaction(function () use ($data, $hitung, $user, $approve, $submit) {
            $order = new PurchaseOrder(['number' => $this->numbers->next(DocumentType::PurchaseOrder, $data['order_date']), 'user_id' => $user->getKey()]);
            $order->forceFill(['status' => PurchaseOrderStatus::Draft]);
            $this->fill($order, $data, $hitung);

            activity('pembelian')->performedOn($order)->causedBy($user)->event('created')
                ->withProperties(['supplier' => $order->supplier->name, 'total' => $order->grand_total])
                ->log("Purchase order {$order->number} dibuat");

            return $this->finish($order, $user, $approve, $submit);
        });
    }

    /** Ganti isi draft. */
    public function update(PurchaseOrder $order, array $data, User $user, bool $approve = false, bool $submit = false): PurchaseOrder
    {
        $hitung = $this->hitung($data);

        return DB::transaction(function () use ($order, $data, $hitung, $user, $approve, $submit) {
            $terkunci = $this->lock($order);
            $this->ensureStatus($terkunci, [PurchaseOrderStatus::Draft], 'diubah');

            $terkunci->items()->delete();
            $this->fill($terkunci, $data, $hitung);

            activity('pembelian')->performedOn($terkunci)->causedBy($user)->event('updated')
                ->withProperties(['total' => $terkunci->grand_total])
                ->log("Purchase order {$terkunci->number} diubah");

            return $this->finish($terkunci, $user, $approve, $submit);
        });
    }

    public function approve(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $terkunci = $this->lock($order);
            $this->ensureStatus($terkunci, [PurchaseOrderStatus::Draft], 'disetujui');

            if (! $terkunci->items()->exists()) {
                throw new AturanBisnisException('Purchase order tanpa barang tidak bisa disetujui.');
            }

            if (($flow = $this->approvals->flowFor($terkunci)) !== null) {
                throw new AturanBisnisException("Purchase order {$terkunci->number} perlu persetujuan berjenjang ({$flow->name}). Ajukan persetujuan.");
            }

            $terkunci->forceFill([
                'status' => PurchaseOrderStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $user->getKey(),
            ])->save();

            activity('pembelian')->performedOn($terkunci)->causedBy($user)->event('approved')
                ->log("Purchase order {$terkunci->number} disetujui");

            return $terkunci->load('items');
        });
    }

    /** Ajukan draft ke alur persetujuan yang cocok. Tahap 1 diberi notifikasi. */
    public function submit(PurchaseOrder $order, User $user, ?string $note = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user, $note) {
            $terkunci = $this->lock($order);
            $this->ensureStatus($terkunci, [PurchaseOrderStatus::Draft], 'diajukan');

            if (! $terkunci->items()->exists()) {
                throw new AturanBisnisException('Purchase order tanpa barang tidak bisa diajukan.');
            }

            $this->approvals->submit($terkunci, $user, $note);

            return $terkunci->refresh()->load('items');
        });
    }

    /** Batalkan PO yang belum menerima barang apa pun. */
    public function cancel(PurchaseOrder $order, string $alasan, User $user): PurchaseOrder
    {
        $alasan = $this->alasan($alasan);

        return DB::transaction(function () use ($order, $alasan, $user) {
            $terkunci = $this->lock($order);
            $this->ensureStatus($terkunci, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted, PurchaseOrderStatus::Approved], 'dibatalkan');

            if (($pending = $terkunci->pendingApproval()) !== null) {
                $this->approvals->cancel($pending, $user, $alasan, force: true);
            }

            if ($terkunci->hasActiveReceipts()) {
                throw new AturanBisnisException("Purchase order {$terkunci->number} sudah menerima barang. Tutup PO bila sisanya tidak akan dikirim.");
            }

            $terkunci->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancel_reason' => $alasan,
            ])->save();

            activity('pembelian')->performedOn($terkunci)->causedBy($user)->event('cancelled')
                ->withProperties(['alasan' => $alasan])
                ->log("Purchase order {$terkunci->number} dibatalkan");

            return $terkunci;
        });
    }

    /** Tutup PO yang sudah diterima sebagian: sisa barang tidak lagi ditunggu. */
    public function close(PurchaseOrder $order, string $alasan, User $user): PurchaseOrder
    {
        $alasan = $this->alasan($alasan);

        return DB::transaction(function () use ($order, $alasan, $user) {
            $terkunci = $this->lock($order);
            $this->ensureStatus($terkunci, [PurchaseOrderStatus::Partial], 'ditutup');

            $terkunci->forceFill([
                'status' => PurchaseOrderStatus::Completed,
                'closed_at' => now(),
                'closed_by' => $user->getKey(),
                'cancel_reason' => $alasan,
            ])->save();

            activity('pembelian')->performedOn($terkunci)->causedBy($user)->event('closed')
                ->withProperties(['alasan' => $alasan])
                ->log("Purchase order {$terkunci->number} ditutup sebelum diterima semua");

            return $terkunci;
        });
    }

    /**
     * Setelah disimpan: biarkan draft, setujui langsung, atau ajukan bila ada alur yang cocok
     * ("Simpan & setujui" untuk PO yang perlu persetujuan berjenjang otomatis menjadi pengajuan).
     */
    private function finish(PurchaseOrder $order, User $user, bool $approve, bool $submit): PurchaseOrder
    {
        if (($approve || $submit) && $this->approvals->requiresApproval($order)) {
            $this->approvals->submit($order, $user);

            return $order->refresh()->load('items');
        }

        return $approve ? $this->approve($order, $user) : $order->load('items');
    }

    /** Hapus draft yang tidak jadi dipakai. */
    public function delete(PurchaseOrder $order, User $user): void
    {
        DB::transaction(function () use ($order, $user) {
            $terkunci = $this->lock($order);
            $this->ensureStatus($terkunci, [PurchaseOrderStatus::Draft], 'dihapus');

            $terkunci->items()->delete();
            $terkunci->delete();

            activity('pembelian')->causedBy($user)->event('deleted')
                ->withProperties(['nomor' => $terkunci->number])
                ->log("Draft purchase order {$terkunci->number} dihapus");
        });
    }

    /**
     * Status setelah penerimaan dibuat atau dibatalkan: dihitung ulang dari jumlah yang sudah diterima.
     * PO yang ditutup manual tetap selesai, kecuali penerimaannya dibatalkan semua (kembali disetujui).
     * Harus dipanggil di dalam transaksi yang mengunci PO.
     */
    public function refreshStatus(PurchaseOrder $locked): void
    {
        $items = $locked->items()->get();
        $received = $items->contains(fn ($i) => (float) $i->received_quantity > 0);
        $complete = $items->every(fn ($i) => $i->remainingQuantity() <= 0);

        $status = match (true) {
            $complete => PurchaseOrderStatus::Completed,
            $locked->closed_at !== null && $received => PurchaseOrderStatus::Completed,
            $received => PurchaseOrderStatus::Partial,
            default => PurchaseOrderStatus::Approved,
        };

        $locked->forceFill([
            'status' => $status,
            // Tidak ada lagi barang yang diterima: penutupan manual tidak berlaku lagi.
            'closed_at' => $received ? $locked->closed_at : null,
            'closed_by' => $received ? $locked->closed_by : null,
        ])->save();
    }

    public function lock(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @param list<PurchaseOrderStatus> $allowed */
    private function ensureStatus(PurchaseOrder $order, array $allowed, string $action): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw new AturanBisnisException("Purchase order {$order->number} berstatus {$order->status->label()} dan tidak bisa {$action}.");
        }
    }

    /** @param array{baris:array<int,array{milli:int,harga:int,diskon:int,total:int}>, subtotal:int, diskonBaris:int, diskon:int, pajak:int, biaya:int, total:int} $hitung */
    private function fill(PurchaseOrder $order, array $data, array $hitung): void
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $gudang = Warehouse::query()->active()->findOrFail($data['warehouse_id']);
        $produk = Product::with('unit:id,name')->whereIn('id', array_keys($hitung['baris']))->get()->keyBy('id');

        if ($produk->count() !== count($hitung['baris'])) {
            throw new AturanBisnisException('Ada produk yang tidak ditemukan atau sudah dihapus. Muat ulang halaman.');
        }

        $order->fill([
            'supplier_id' => $supplier->getKey(),
            'warehouse_id' => $gudang->getKey(),
            'order_date' => $data['order_date'],
            'expected_date' => $data['expected_date'] ?? null,
            'note' => $data['note'] ?? null,
            'subtotal' => Uang::keDesimal($hitung['subtotal']),
            'item_discount' => Uang::keDesimal($hitung['diskonBaris']),
            'discount' => Uang::keDesimal($hitung['diskon']),
            'tax' => Uang::keDesimal($hitung['pajak']),
            'other_cost' => Uang::keDesimal($hitung['biaya']),
            'grand_total' => Uang::keDesimal($hitung['total']),
        ])->save();

        $baris = $hitung['baris'];
        ksort($baris);

        foreach ($baris as $id => $b) {
            $p = $produk[$id];
            $order->items()->create([
                'product_id' => $p->getKey(),
                'product_name' => $p->name,
                'sku' => $p->sku,
                'unit_name' => $p->unit?->name ?? '-',
                'quantity' => Uang::milliKeDesimal($b['milli']),
                'unit_price' => Uang::keDesimal($b['harga']),
                'discount' => Uang::keDesimal($b['diskon']),
                'line_total' => Uang::keDesimal($b['total']),
            ]);
        }

        $order->setRelation('supplier', $supplier);
    }

    /** @return array{baris:array<int,array{milli:int,harga:int,diskon:int,total:int}>, subtotal:int, diskonBaris:int, diskon:int, pajak:int, biaya:int, total:int} */
    private function hitung(array $data): array
    {
        [$baris, $errors] = DocumentTotals::lines($data['items'] ?? []);
        $hitung = DocumentTotals::totals($baris, Uang::sen($data['discount'] ?? 0), Uang::sen($data['tax'] ?? 0), Uang::sen($data['other_cost'] ?? 0));
        $errors += DocumentTotals::headerErrors($hitung);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $hitung;
    }

    private function alasan(string $alasan): string
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan minimal 5 karakter.']);
        }

        return $alasan;
    }
}
