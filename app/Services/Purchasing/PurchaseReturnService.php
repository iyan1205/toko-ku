<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Events\PurchaseReturned;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Settings\DocumentNumberService;
use App\Services\StockService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur pembelian atas satu faktur.
 *
 *   - Draft (izin purchasing.returns.create): belum berpengaruh apa pun, bisa dihapus.
 *   - Disetujui (izin purchasing.returns.approve): stok keluar dari gudang retur, jumlah retur di
 *     faktur bertambah, dan hutang faktur berkurang. Bila nilai retur melebihi sisa hutang (barang
 *     sudah dibayar), kelebihannya dicatat sebagai refund_amount yang harus dikembalikan supplier.
 *
 * Nilai retur = jumlah x harga bersih per unit di faktur (setelah diskon baris). Retur yang
 * menghabiskan sisa satu baris memakai sisa nilai baris itu, sehingga total retur tidak pernah
 * melebihi nilai baris faktur.
 */
class PurchaseReturnService
{
    public function __construct(
        private readonly StockService $stok,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @param  array{warehouse_id:int, return_date:string, reason:string, items:array<int|string, mixed>}  $data
     *                                                                                                            items = purchase_item_id => jumlah diretur (0/kosong = tidak diretur)
     */
    public function create(Purchase $purchase, array $data, User $user, bool $approve = false): PurchaseReturn
    {
        app(FiscalPeriodService::class)->ensureOpen($data['return_date']);

        return DB::transaction(function () use ($purchase, $data, $user, $approve) {
            $faktur = Purchase::whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureReturnable($faktur);

            $gudang = Warehouse::query()->active()->findOrFail($data['warehouse_id']);
            $items = $faktur->items()->orderBy('product_id')->get()->keyBy('id');
            $jumlah = $this->quantities($items, $data['items'] ?? []);

            $retur = PurchaseReturn::create([
                'number' => $this->numbers->next(DocumentType::PurchaseReturn, $data['return_date']),
                'purchase_id' => $faktur->getKey(),
                'supplier_id' => $faktur->supplier_id,
                'warehouse_id' => $gudang->getKey(),
                'return_date' => $data['return_date'],
                'reason' => trim($data['reason']),
                'user_id' => $user->getKey(),
            ]);

            $total = 0;
            foreach ($jumlah as $itemId => $milli) {
                $item = $items[$itemId];
                $nilai = $this->lineValue($item, $milli);
                $total += $nilai;

                $retur->items()->create([
                    'purchase_item_id' => $item->getKey(),
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'unit_name' => $item->unit_name,
                    'quantity' => Uang::milliKeDesimal($milli),
                    'unit_price' => Uang::keDesimal(Uang::perUnit(Uang::sen($item->line_total), Uang::milli($item->quantity))),
                    'line_total' => Uang::keDesimal($nilai),
                ]);
            }

            $retur->forceFill(['status' => PurchaseReturnStatus::Draft, 'total' => Uang::keDesimal($total)])->save();

            activity('pembelian')->performedOn($retur)->causedBy($user)->event('created')
                ->withProperties(['faktur' => $faktur->number, 'nilai' => $retur->total])
                ->log("Retur pembelian {$retur->number} dibuat");

            return $approve ? $this->approve($retur, $user) : $retur->load('items');
        });
    }

    public function approve(PurchaseReturn $return, User $user): PurchaseReturn
    {
        // Stok keluar, pengurangan hutang, dan jurnal retur dibukukan pada tanggal persetujuan.
        app(FiscalPeriodService::class)->ensureOpen(now());

        return DB::transaction(function () use ($return, $user) {
            // Faktur dikunci dulu: semua persetujuan retur & pembayaran faktur yang sama berjalan bergiliran.
            $faktur = Purchase::whereKey($return->purchase_id)->lockForUpdate()->firstOrFail();
            $retur = PurchaseReturn::whereKey($return->getKey())->lockForUpdate()->with(['items', 'warehouse'])->firstOrFail();

            if (! $retur->isDraft()) {
                throw new AturanBisnisException("Retur {$retur->number} sudah disetujui.");
            }

            $this->ensureReturnable($faktur);
            $items = $faktur->items()->get()->keyBy('id');

            // Periksa ulang terhadap retur lain yang disetujui sejak draft ini dibuat.
            foreach ($retur->items as $baris) {
                $item = $items[$baris->purchase_item_id];
                if (Uang::milli($baris->quantity) > Uang::milli($item->quantity) - Uang::milli($item->returned_quantity)) {
                    throw new AturanBisnisException(sprintf(
                        '%s: jumlah retur melebihi sisa yang bisa diretur (%s %s). Hapus draft ini dan buat ulang.',
                        $item->product_name, Format::jumlah($item->returnableQuantity()), $item->unit_name,
                    ));
                }
            }

            try {
                foreach ($retur->items->sortBy('product_id') as $baris) {
                    $this->stok->catat(
                        Product::withTrashed()->findOrFail($baris->product_id),
                        StockMovementType::PurchaseReturn,
                        '-'.$baris->quantity,
                        $retur,
                        "Retur {$retur->number} ke supplier: {$retur->reason}",
                        $user,
                        $retur->warehouse,
                    );
                }
            } catch (AturanBisnisException $e) {
                throw new AturanBisnisException("Stok di {$retur->warehouse->name} tidak cukup untuk retur ini. ".$e->getMessage());
            }

            foreach ($retur->items as $baris) {
                $item = $items[$baris->purchase_item_id];
                $item->forceFill(['returned_quantity' => Uang::milliKeDesimal(Uang::milli($item->returned_quantity) + Uang::milli($baris->quantity))])->save();
            }

            $nilai = Uang::sen($retur->total);
            $sisa = Uang::sen($faktur->balance);
            $mengurangiHutang = min($nilai, $sisa);
            $sisaBaru = $sisa - $mengurangiHutang;

            $faktur->forceFill([
                'returned_amount' => Uang::keDesimal(Uang::sen($faktur->returned_amount) + $nilai),
                'balance' => Uang::keDesimal($sisaBaru),
                'payment_status' => PaymentStatus::dari(Uang::sen($faktur->paid_amount), $sisaBaru),
            ])->save();

            $retur->forceFill([
                'status' => PurchaseReturnStatus::Approved,
                'refund_amount' => Uang::keDesimal($nilai - $mengurangiHutang),
                'approved_at' => now(),
                'approved_by' => $user->getKey(),
            ])->save();

            activity('pembelian')->performedOn($retur)->causedBy($user)->event('approved')
                ->withProperties(['faktur' => $faktur->number, 'nilai' => $retur->total, 'refund' => $retur->refund_amount])
                ->log("Retur pembelian {$retur->number} disetujui");

            PurchaseReturned::dispatch($retur);

            return $retur->load('items');
        });
    }

    /** Hapus draft yang tidak jadi dipakai. Retur yang sudah disetujui tidak bisa dihapus. */
    public function delete(PurchaseReturn $return, User $user): void
    {
        DB::transaction(function () use ($return, $user) {
            $retur = PurchaseReturn::whereKey($return->getKey())->lockForUpdate()->firstOrFail();

            if (! $retur->isDraft()) {
                throw new AturanBisnisException("Retur {$retur->number} sudah disetujui dan tidak bisa dihapus.");
            }

            $retur->items()->delete();
            $retur->delete();

            activity('pembelian')->causedBy($user)->event('deleted')
                ->withProperties(['nomor' => $retur->number])
                ->log("Draft retur pembelian {$retur->number} dihapus");
        });
    }

    private function ensureReturnable(Purchase $faktur): void
    {
        if ($faktur->status !== PurchaseStatus::Completed) {
            throw new AturanBisnisException("Faktur {$faktur->number} sudah dibatalkan dan tidak bisa diretur.");
        }
    }

    /**
     * @param  Collection<int, PurchaseItem>  $items
     * @param  array<int|string, mixed>  $input
     * @return array<int, int> purchase_item_id => milli, urut product_id
     */
    private function quantities(Collection $items, array $input): array
    {
        $hasil = [];
        $errors = [];

        foreach ($items as $id => $item) {
            $milli = Uang::milli($input[$id] ?? 0);

            if ($milli <= 0) {
                continue;
            }

            if ($milli > Uang::milli($item->quantity) - Uang::milli($item->returned_quantity)) {
                $errors["items.{$id}"] = sprintf('%s: jumlah retur melebihi sisa yang bisa diretur (%s %s).',
                    $item->product_name, Format::jumlah($item->returnableQuantity()), $item->unit_name);

                continue;
            }

            $hasil[$id] = $milli;
        }

        $asing = array_diff(array_map('intval', array_keys($input)), $items->keys()->all());
        if ($asing !== []) {
            throw new AturanBisnisException('Ada barang yang bukan bagian dari faktur ini. Muat ulang halaman.');
        }

        if ($hasil === [] && $errors === []) {
            $errors['items'] = 'Isi jumlah retur minimal untuk satu barang.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $hasil;
    }

    /** Nilai retur sebuah baris. Retur yang menghabiskan sisa baris mendapat sisa nilai baris itu. */
    private function lineValue(PurchaseItem $item, int $milli): int
    {
        $dibeli = Uang::milli($item->quantity);
        $sudah = Uang::milli($item->returned_quantity);
        $nilaiBaris = Uang::sen($item->line_total);

        if ($sudah + $milli >= $dibeli) {
            $sudahNilai = Uang::sen($item->returnItems()
                ->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturnStatus::Approved->value))
                ->sum('line_total'));

            return max(0, $nilaiBaris - $sudahNilai);
        }

        return (int) round($nilaiBaris * $milli / $dibeli);
    }
}
