<?php

namespace App\Services\Inventory;

use App\Enums\DocumentType;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Events\StockTransferReceived;
use App\Events\StockTransferred;
use App\Events\StockTransferVoided;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Settings\DocumentNumberService;
use App\Services\StockService;
use App\Support\Format;
use App\Support\Uang;
use App\Support\UnitConversion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfer stok antar gudang.
 *
 *   - Gudang dalam satu cabang: langsung selesai. Setiap produk keluar dari gudang asal (TransferOut)
 *     lalu masuk ke gudang tujuan (TransferIn) dalam satu transaksi.
 *   - Antar cabang: dikirim dulu (stok keluar dari gudang asal, status Dalam perjalanan), lalu diterima
 *     gudang tujuan dengan jumlah yang benar-benar datang. Kekurangan dicatat sebagai selisih persediaan.
 *
 * Batch, kedaluwarsa, biaya, dan nomor seri ikut pindah. Pembatalan membalik mutasinya; transfer yang
 * sudah diterima ditolak bila barang sudah keluar dari gudang tujuan atau ada kekurangan saat diterima.
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
        $data['items'] = UnitConversion::toBase($data['items'] ?? []);
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
            $antarCabang = StockTransfer::isInterBranch($dari, $ke);
            $transfer->forceFill(['status' => $antarCabang ? StockTransferStatus::InTransit : StockTransferStatus::Completed])->save();

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
                $keluar = $this->stok->catat($p, StockMovementType::TransferOut, '-'.$jumlah, $transfer, "Transfer {$transfer->number} ke {$ke->name}", $user, $dari);
                if (! $antarCabang) {
                    // Batch, kedaluwarsa, biaya, dan nomor seri ikut pindah ke gudang tujuan.
                    $this->stok->catat($p, StockMovementType::TransferIn, $jumlah, $transfer, "Transfer {$transfer->number} dari {$dari->name}", $user, $ke, ['from_movement' => $keluar]);
                }
            }

            activity('inventori')->performedOn($transfer)->causedBy($user)->event('created')
                ->withProperties(['dari' => $dari->name, 'ke' => $ke->name, 'produk' => count($baris)])
                ->log("Transfer stok {$transfer->number} dari {$dari->name} ke {$ke->name}");

            StockTransferred::dispatch($transfer);

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
            $terkunci = StockTransfer::query()->visible()->whereKey($transfer->getKey())->lockForUpdate()->with(['items', 'fromWarehouse', 'toWarehouse'])->firstOrFail();

            if ($terkunci->dibatalkan()) {
                throw new AturanBisnisException('Transfer ini sudah dibatalkan.');
            }

            $catatan = "Pembatalan transfer {$terkunci->number}: {$alasan}";

            if ($terkunci->inTransit()) {
                // Belum diterima: barang kembali ke gudang asal dari lot aslinya.
                foreach ($terkunci->items->sortBy('product_id') as $item) {
                    $p = Product::withTrashed()->findOrFail($item->product_id);
                    $this->stok->catat($p, StockMovementType::TransferOut, $item->quantity, $terkunci, $catatan, $user, $terkunci->fromWarehouse,
                        ['reverse_of' => StockService::mutasiDokumen($terkunci, $p->id, -1)]);
                }
                $this->tandaiBatal($terkunci, $alasan, $user);

                return $terkunci;
            }

            if ($terkunci->hasShortage()) {
                throw new AturanBisnisException("Transfer {$terkunci->number} diterima dengan kekurangan, jadi tidak bisa dibatalkan. Buat transfer baru ke arah sebaliknya untuk barang yang ada.");
            }

            try {
                foreach ($terkunci->items->sortBy('product_id') as $item) {
                    $p = Product::withTrashed()->findOrFail($item->product_id);
                    $this->stok->catat($p, StockMovementType::TransferIn, '-'.$item->quantity, $terkunci, $catatan, $user, $terkunci->toWarehouse,
                        ['reverse_of' => StockService::mutasiDokumen($terkunci, $p->id, 1)]);
                    $this->stok->catat($p, StockMovementType::TransferOut, $item->quantity, $terkunci, $catatan, $user, $terkunci->fromWarehouse,
                        ['reverse_of' => StockService::mutasiDokumen($terkunci, $p->id, -1)]);
                }
            } catch (AturanBisnisException $e) {
                throw new AturanBisnisException(
                    "Transfer {$terkunci->number} tidak dapat dibatalkan karena sebagian barang sudah keluar dari {$terkunci->toWarehouse->name}. "
                    .$e->getMessage().' Buat transfer baru ke arah sebaliknya untuk barang yang masih ada.'
                );
            }

            $this->tandaiBatal($terkunci, $alasan, $user);

            return $terkunci;
        });
    }

    /**
     * Terima transfer antar cabang di gudang tujuan. $jumlah: item_id => jumlah diterima (tidak boleh
     * melebihi yang dikirim; kosong = sesuai kiriman). Kekurangan tidak masuk stok dan dijurnal sebagai selisih.
     *
     * @param  array<int|string, mixed>  $jumlah
     */
    public function receive(StockTransfer $transfer, array $jumlah, ?string $catatan, User $user): StockTransfer
    {
        app(FiscalPeriodService::class)->ensureOpen(now());

        return DB::transaction(function () use ($transfer, $jumlah, $catatan, $user) {
            $terkunci = StockTransfer::query()->visible()->whereKey($transfer->getKey())->lockForUpdate()->with(['items', 'fromWarehouse', 'toWarehouse'])->firstOrFail();

            if (! $terkunci->inTransit()) {
                throw new AturanBisnisException("Transfer {$terkunci->number} berstatus {$terkunci->status->label()}, tidak ada yang perlu diterima.");
            }

            $errors = [];
            foreach ($terkunci->items->sortBy('product_id') as $item) {
                $milli = array_key_exists($item->id, $jumlah) && $jumlah[$item->id] !== null && $jumlah[$item->id] !== ''
                    ? Uang::milli($jumlah[$item->id]) : Uang::milli($item->quantity);
                if ($milli < 0 || $milli > Uang::milli($item->quantity)) {
                    $errors["received.{$item->id}"] = "{$item->product_name}: jumlah diterima 0 sampai ".Format::jumlah($item->quantity).'.';

                    continue;
                }
                $item->forceFill(['received_quantity' => Uang::milliKeDesimal($milli)])->save();
                if ($milli > 0) {
                    $p = Product::withTrashed()->findOrFail($item->product_id);
                    $keluar = StockService::mutasiDokumen($terkunci, $p->id, -1)->first();
                    $this->stok->catat($p, StockMovementType::TransferIn, Uang::milliKeDesimal($milli), $terkunci,
                        "Terima transfer {$terkunci->number} dari {$terkunci->fromWarehouse->name}", $user, $terkunci->toWarehouse, ['from_movement' => $keluar]);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $terkunci->forceFill([
                'status' => StockTransferStatus::Completed, 'received_at' => now(), 'received_by' => $user->getKey(),
                'receive_note' => $catatan ? mb_substr(trim($catatan), 0, 255) : null,
            ])->save();

            activity('inventori')->performedOn($terkunci)->causedBy($user)->event('received')
                ->withProperties(['kurang' => $terkunci->fresh('items')->hasShortage()])
                ->log("Transfer stok {$terkunci->number} diterima di {$terkunci->toWarehouse->name}");

            StockTransferReceived::dispatch($terkunci);

            return $terkunci;
        });
    }

    private function tandaiBatal(StockTransfer $transfer, string $alasan, User $user): void
    {
        $transfer->forceFill([
            'status' => StockTransferStatus::Voided,
            'voided_at' => now(),
            'voided_by' => $user->getKey(),
            'void_reason' => $alasan,
        ])->save();

        activity('inventori')->performedOn($transfer)->causedBy($user)->event('voided')
            ->withProperties(['alasan' => $alasan])
            ->log("Transfer stok {$transfer->number} dibatalkan");

        StockTransferVoided::dispatch($transfer);
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
