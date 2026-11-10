<?php

namespace App\Services\Pos;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SaleReturnStatus;
use App\Enums\StockMovementType;
use App\Events\SaleReturned;
use App\Exceptions\AturanBisnisException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\PaymentService;
use App\Services\Settings\DocumentNumberService;
use App\Services\StockService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur penjualan atas satu transaksi kasir.
 *
 *   - Draft (izin pos.returns.create): diajukan, belum berpengaruh apa pun, bisa dihapus.
 *   - Disetujui (izin pos.returns.approve): stok kembali ke gudang penjualan, jumlah retur di
 *     transaksi bertambah, piutang transaksi berkurang dulu, dan sisanya dikembalikan ke pelanggan
 *     (refund_amount, dicatat sebagai uang keluar di sesi kasir penyetuju bila sesinya terbuka).
 *
 * Nilai retur = bagian baris itu dari total yang dibayar pelanggan, jadi diskon transaksi dan pajak
 * ikut terbagi rata: nilai baris = line_total x grand_total / jumlah line_total. Retur yang
 * menghabiskan sisa satu baris memakai sisa nilai baris itu, sehingga total retur tidak pernah
 * melebihi total transaksi.
 */
class SaleReturnService
{
    public function __construct(
        private readonly StockService $stok,
        private readonly PaymentService $payments,
        private readonly DocumentNumberService $numbers,
        private readonly CashSessionService $sessions,
    ) {}

    /**
     * @param  array{reason:string, items:array<int|string, mixed>, refund_method?:?string}  $data
     *                                                                                              items = sale_item_id => jumlah diretur (0/kosong = tidak diretur)
     */
    public function create(Sale $sale, array $data, User $user, bool $approve = false): SaleReturn
    {
        return DB::transaction(function () use ($sale, $data, $user, $approve) {
            $penjualan = Sale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureReturnable($penjualan);

            $items = $penjualan->items()->orderBy('product_id')->get()->keyBy('id');
            $jumlah = $this->quantities($items, $data['items'] ?? []);
            $tanggal = now()->toDateString();

            app(FiscalPeriodService::class)->ensureOpen($tanggal);

            $retur = SaleReturn::create([
                'number' => $this->numbers->next(DocumentType::SaleReturn, $tanggal),
                'sale_id' => $penjualan->getKey(),
                'customer_id' => $penjualan->customer_id,
                'warehouse_id' => $penjualan->warehouse_id ?? $this->stok->defaultWarehouse()->getKey(),
                'user_id' => $user->getKey(),
                'return_date' => $tanggal,
                'reason' => trim($data['reason']),
            ]);

            $total = 0;
            foreach ($jumlah as $itemId => $milli) {
                $item = $items[$itemId];
                $nilai = $this->lineValue($penjualan, $items, $item, $milli);
                $total += $nilai;

                $retur->items()->create([
                    'sale_item_id' => $item->getKey(),
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'unit_name' => $item->unit_name,
                    'quantity' => Uang::milliKeDesimal($milli),
                    'unit_price' => Uang::keDesimal(Uang::perUnit($nilai, $milli)),
                    'line_total' => Uang::keDesimal($nilai),
                ]);
            }

            $retur->forceFill(['status' => SaleReturnStatus::Draft, 'total' => Uang::keDesimal($total)])->save();

            activity('penjualan')->performedOn($retur)->causedBy($user)->event('created')
                ->withProperties(['penjualan' => $penjualan->number, 'nilai' => $retur->total])
                ->log("Retur penjualan {$retur->number} diajukan");

            return $approve
                ? $this->approve($retur, $user, PaymentMethod::tryFrom((string) ($data['refund_method'] ?? '')) ?? PaymentMethod::Cash)
                : $retur->load('items');
        });
    }

    public function approve(SaleReturn $return, User $user, PaymentMethod $refundMethod = PaymentMethod::Cash): SaleReturn
    {
        // Stok, pengembalian uang, dan jurnal retur dibukukan pada tanggal persetujuan.
        app(FiscalPeriodService::class)->ensureOpen(now());

        return DB::transaction(function () use ($return, $user, $refundMethod) {
            // Penjualan dikunci dulu: semua persetujuan retur & pelunasan transaksi yang sama berjalan bergiliran.
            $penjualan = Sale::whereKey($return->sale_id)->lockForUpdate()->firstOrFail();
            $retur = SaleReturn::whereKey($return->getKey())->lockForUpdate()->with('items')->firstOrFail();

            if (! $retur->isDraft()) {
                throw new AturanBisnisException("Retur {$retur->number} sudah disetujui.");
            }

            $this->ensureReturnable($penjualan);
            $items = $penjualan->items()->get()->keyBy('id');

            // Periksa ulang terhadap retur lain yang disetujui sejak draft ini dibuat.
            foreach ($retur->items as $baris) {
                $item = $items[$baris->sale_item_id];
                if (Uang::milli($baris->quantity) > Uang::milli($item->quantity) - Uang::milli($item->returned_quantity)) {
                    throw new AturanBisnisException(sprintf(
                        '%s: jumlah retur melebihi sisa yang bisa diretur (%s %s). Hapus pengajuan ini dan buat ulang.',
                        $item->product_name, Format::jumlah($item->returnableQuantity()), $item->unit_name,
                    ));
                }
            }

            $gudang = Warehouse::withTrashed()->findOrFail($retur->warehouse_id);

            foreach ($retur->items->sortBy('product_id') as $baris) {
                $this->stok->catat(
                    Product::withTrashed()->findOrFail($baris->product_id),
                    StockMovementType::SaleReturn,
                    $baris->quantity,
                    $retur,
                    "Retur {$retur->number} dari pelanggan: {$retur->reason}",
                    $user,
                    $gudang,
                );
            }

            foreach ($retur->items as $baris) {
                $item = $items[$baris->sale_item_id];
                $item->forceFill(['returned_quantity' => Uang::milliKeDesimal(Uang::milli($item->returned_quantity) + Uang::milli($baris->quantity))])->save();
            }

            // Uang: kurangi sisa piutang transaksi ini dulu, kelebihannya dikembalikan ke pelanggan.
            $nilai = Uang::sen($retur->total);
            $sisa = Uang::sen($penjualan->balance);
            $kurangiPiutang = min($nilai, $sisa);
            $refund = $nilai - $kurangiPiutang;
            $sisaBaru = $sisa - $kurangiPiutang;

            if ($kurangiPiutang > 0) {
                $pelanggan = Customer::whereKey($penjualan->customer_id)->lockForUpdate()->firstOrFail();
                $pelanggan->forceFill([
                    'receivable_balance' => Uang::keDesimal(Uang::sen($pelanggan->receivable_balance) - $kurangiPiutang),
                ])->save();
            }

            $penjualan->forceFill([
                'returned_amount' => Uang::keDesimal(Uang::sen($penjualan->returned_amount) + $nilai),
                'balance' => Uang::keDesimal($sisaBaru),
                'payment_status' => PaymentStatus::dari(Uang::sen($penjualan->paid_amount), $sisaBaru),
            ])->save();

            if ($refund > 0) {
                $this->payments->refundSaleReturn($retur, $refund, $refundMethod, $user, $this->sessions->lockCurrent($user));
            }

            $retur->forceFill([
                'status' => SaleReturnStatus::Approved,
                'receivable_reduction' => Uang::keDesimal($kurangiPiutang),
                'refund_amount' => Uang::keDesimal($refund),
                'refund_method' => $refund > 0 ? $refundMethod : null,
                'approved_at' => now(),
                'approved_by' => $user->getKey(),
            ])->save();

            activity('penjualan')->performedOn($retur)->causedBy($user)->event('approved')
                ->withProperties([
                    'penjualan' => $penjualan->number, 'nilai' => $retur->total,
                    'mengurangi_piutang' => $retur->receivable_reduction, 'uang_kembali' => $retur->refund_amount,
                ])
                ->log("Retur penjualan {$retur->number} disetujui");

            SaleReturned::dispatch($retur);

            return $retur->load('items');
        });
    }

    /** Hapus pengajuan yang tidak jadi dipakai. Retur yang sudah disetujui tidak bisa dihapus. */
    public function delete(SaleReturn $return, User $user): void
    {
        DB::transaction(function () use ($return, $user) {
            $retur = SaleReturn::whereKey($return->getKey())->lockForUpdate()->firstOrFail();

            if (! $retur->isDraft()) {
                throw new AturanBisnisException("Retur {$retur->number} sudah disetujui dan tidak bisa dihapus.");
            }

            $retur->items()->delete();
            $retur->delete();

            activity('penjualan')->causedBy($user)->event('deleted')
                ->withProperties(['nomor' => $retur->number])
                ->log("Pengajuan retur penjualan {$retur->number} dihapus");
        });
    }

    private function ensureReturnable(Sale $penjualan): void
    {
        if ($penjualan->status !== PurchaseStatus::Completed) {
            throw new AturanBisnisException("Penjualan {$penjualan->number} sudah dibatalkan dan tidak bisa diretur.");
        }
    }

    /**
     * @param  Collection<int, SaleItem>  $items
     * @param  array<int|string, mixed>  $input
     * @return array<int, int> sale_item_id => milli, urut product_id
     */
    private function quantities(Collection $items, array $input): array
    {
        $hasil = [];
        $errors = [];

        $asing = array_diff(array_map('intval', array_keys($input)), $items->keys()->all());
        if ($asing !== []) {
            throw new AturanBisnisException('Ada barang yang bukan bagian dari transaksi ini. Muat ulang halaman.');
        }

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

        if ($hasil === [] && $errors === []) {
            $errors['items'] = 'Isi jumlah retur minimal untuk satu barang.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $hasil;
    }

    /**
     * Nilai retur sebuah baris (sen). Retur yang menghabiskan sisa baris mendapat sisa nilai baris itu.
     *
     * @param  Collection<int, SaleItem>  $items
     */
    private function lineValue(Sale $penjualan, Collection $items, SaleItem $item, int $milli): int
    {
        $nilaiBaris = $this->lineShare($penjualan, $items, $item);
        $terjual = Uang::milli($item->quantity);
        $sudah = Uang::milli($item->returned_quantity);

        if ($sudah + $milli >= $terjual) {
            $sudahNilai = Uang::sen($item->returnItems()
                ->whereHas('saleReturn', fn ($q) => $q->where('status', SaleReturnStatus::Approved->value))
                ->sum('line_total'));

            return max(0, $nilaiBaris - $sudahNilai);
        }

        return Uang::kaliBagi($nilaiBaris, $milli, $terjual);
    }

    /**
     * Bagian baris dari grand_total (sen). Sisa pembulatan diberikan ke baris terakhir (id terbesar),
     * sehingga jumlah semua bagian sama persis dengan grand_total.
     *
     * @param  Collection<int, SaleItem>  $items
     */
    private function lineShare(Sale $penjualan, Collection $items, SaleItem $item): int
    {
        $grand = Uang::sen($penjualan->grand_total);
        $jumlahBaris = $items->sum(fn (SaleItem $i) => Uang::sen($i->line_total));

        if ($jumlahBaris <= 0) {
            return 0;
        }

        $terakhir = $items->sortBy('id')->last();
        if ($item->is($terakhir)) {
            $lain = $items->reject(fn (SaleItem $i) => $i->is($terakhir))
                ->sum(fn (SaleItem $i) => Uang::kaliBagi(Uang::sen($i->line_total), $grand, $jumlahBaris));

            return $grand - $lain;
        }

        return Uang::kaliBagi(Uang::sen($item->line_total), $grand, $jumlahBaris);
    }
}
