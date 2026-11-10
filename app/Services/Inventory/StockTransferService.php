<?php

namespace App\Services\Inventory;

use App\Enums\DocumentType;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Settings\DocumentNumberService;
use App\Services\StockService;
use App\Support\Uang;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfer stok antar gudang. Langsung selesai: dalam satu transaksi, setiap produk keluar dari
 * gudang asal (TransferOut) lalu masuk ke gudang tujuan (TransferIn). Total products.stock tidak
 * berubah. Pembatalan membalik kedua mutasi; ditolak bila barang sudah keluar dari gudang tujuan.
 */
class StockTransferService
{
    public function __construct(
        private readonly StockService $stok,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @param array{
     *   from_warehouse_id:int, to_warehouse_id:int, transfer_date:string, note?:?string,
     *   items:array<int, array{product_id:int, quantity:mixed}>
     * } $data
     */
    public function create(array $data, User $user): StockTransfer
    {
        app(FiscalPeriodService::class)->ensureOpen($data['transfer_date']);
        $baris = $this->lines($data['items'] ?? []);

        return DB::transaction(function () use ($data, $baris, $user) {
            $dari = Warehouse::query()->active()->findOrFail($data['from_warehouse_id']);
            $ke = Warehouse::query()->active()->findOrFail($data['to_warehouse_id']);

            if ($dari->is($ke)) {
                throw ValidationException::withMessages(['to_warehouse_id' => 'Gudang tujuan harus berbeda dengan gudang asal.']);
            }

            $produk = Product::with('unit:id,name')->whereIn('id', array_keys($baris))->get()->keyBy('id');
            if ($produk->count() !== count($baris)) {
                throw new AturanBisnisException('Ada produk yang tidak ditemukan atau sudah dihapus. Muat ulang halaman.');
            }

            $transfer = StockTransfer::create([
                'number' => $this->numbers->next(DocumentType::StockTransfer, $data['transfer_date']),
                'from_warehouse_id' => $dari->getKey(),
                'to_warehouse_id' => $ke->getKey(),
                'transfer_date' => $data['transfer_date'],
                'note' => $data['note'] ?? null,
                'user_id' => $user->getKey(),
            ]);
            $transfer->forceFill(['status' => PurchaseStatus::Completed])->save();

            // Urut product_id: urutan kunci baris sama di semua transaksi (mencegah deadlock).
            ksort($baris);
            foreach ($baris as $id => $milli) {
                $p = $produk[$id];
                $jumlah = Uang::milliKeDesimal($milli);

                $transfer->items()->create([
                    'product_id' => $p->getKey(),
                    'product_name' => $p->name,
                    'sku' => $p->sku,
                    'unit_name' => $p->unit?->name ?? '-',
                    'quantity' => $jumlah,
                ]);

                // Gagal dengan "Stok tidak mencukupi." bila stok gudang asal kurang; seluruh transfer dibatalkan.
                $this->stok->catat($p, StockMovementType::TransferOut, '-'.$jumlah, $transfer, "Transfer {$transfer->number} ke {$ke->name}", $user, $dari);
                $this->stok->catat($p, StockMovementType::TransferIn, $jumlah, $transfer, "Transfer {$transfer->number} dari {$dari->name}", $user, $ke);
            }

            activity('inventori')->performedOn($transfer)->causedBy($user)->event('created')
                ->withProperties(['dari' => $dari->name, 'ke' => $ke->name, 'produk' => count($baris)])
                ->log("Transfer stok {$transfer->number} dari {$dari->name} ke {$ke->name}");

            return $transfer->load('items');
        });
    }

    public function void(StockTransfer $transfer, string $alasan, User $user): StockTransfer
    {
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan pembatalan minimal 5 karakter.']);
        }

        app(FiscalPeriodService::class)->ensureOpen(now());

        return DB::transaction(function () use ($transfer, $alasan, $user) {
            $terkunci = StockTransfer::whereKey($transfer->getKey())->lockForUpdate()->with(['items', 'fromWarehouse', 'toWarehouse'])->firstOrFail();

            if ($terkunci->dibatalkan()) {
                throw new AturanBisnisException('Transfer ini sudah dibatalkan.');
            }

            $catatan = "Pembatalan transfer {$terkunci->number}: {$alasan}";

            try {
                foreach ($terkunci->items->sortBy('product_id') as $item) {
                    $p = Product::withTrashed()->findOrFail($item->product_id);
                    $this->stok->catat($p, StockMovementType::TransferIn, '-'.$item->quantity, $terkunci, $catatan, $user, $terkunci->toWarehouse);
                    $this->stok->catat($p, StockMovementType::TransferOut, $item->quantity, $terkunci, $catatan, $user, $terkunci->fromWarehouse);
                }
            } catch (AturanBisnisException $e) {
                throw new AturanBisnisException(
                    "Transfer {$terkunci->number} tidak dapat dibatalkan karena sebagian barang sudah keluar dari {$terkunci->toWarehouse->name}. "
                    .$e->getMessage().' Buat transfer baru ke arah sebaliknya untuk barang yang masih ada.'
                );
            }

            $terkunci->forceFill([
                'status' => PurchaseStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $user->getKey(),
                'void_reason' => $alasan,
            ])->save();

            activity('inventori')->performedOn($terkunci)->causedBy($user)->event('voided')
                ->withProperties(['alasan' => $alasan])
                ->log("Transfer stok {$terkunci->number} dibatalkan");

            return $terkunci;
        });
    }

    /**
     * Baris transfer: product_id => jumlah dalam milli. Semua kesalahan dikumpulkan sekaligus.
     *
     * @param  array<int, array{product_id:int|string, quantity:mixed}>  $items
     * @return array<int, int>
     */
    private function lines(array $items): array
    {
        $baris = [];
        $errors = [];

        foreach (array_values($items) as $i => $item) {
            $id = (int) ($item['product_id'] ?? 0);
            $milli = Uang::milli($item['quantity'] ?? 0);

            if (isset($baris[$id])) {
                $errors["items.{$i}.product_id"] = 'Produk yang sama tidak boleh muncul dua kali. Gabungkan jumlahnya.';
            } elseif ($milli <= 0) {
                $errors["items.{$i}.quantity"] = 'Jumlah harus lebih dari nol.';
            } else {
                $baris[$id] = $milli;
            }
        }

        if ($baris === [] && $errors === []) {
            $errors['items'] = 'Tambahkan minimal satu produk.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $baris;
    }
}
