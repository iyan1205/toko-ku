<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Uang;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    /** Batas total per pembelian: Rp 99.999.999.999,99 (muat di decimal(15,2) dan aman untuk hitungan bilangan bulat). */
    private const BATAS_TOTAL_SEN = 9_999_999_999_999;

    public function __construct(
        private readonly StockService $stok,
        private readonly PaymentService $pembayaran,
        private readonly KodeService $kode,
    ) {
    }

    /**
     * Simpan pembelian: dokumen, rincian, stok masuk (lewat StockService), harga beli produk,
     * dan pembayaran awal. Semuanya satu transaksi: gagal di mana pun, semuanya dibatalkan.
     *
     * @param array{
     *   supplier_id:int, supplier_invoice_number?:?string, purchase_date:string, due_date?:?string,
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

            $ids = array_keys($hitung['baris']);
            sort($ids); // urutan tetap saat mengunci produk: mencegah deadlock antar pembelian
            $produk = Product::whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');

            if ($produk->count() !== count($ids)) {
                throw new AturanBisnisException('Ada produk yang tidak ditemukan atau sudah dihapus. Muat ulang halaman.');
            }

            $tanggal = $data['purchase_date'];

            $pembelian = new Purchase([
                'number' => $this->kode->berikutnya(Purchase::class, 'number', 'PUR-' . date('Ymd', strtotime($tanggal)) . '-', 4),
                'supplier_id' => $supplier->getKey(),
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'purchase_date' => $tanggal,
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => Uang::keDesimal($hitung['subtotal']),
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

            $perbaruiHarga = $data['update_cost'] ?? true;

            foreach ($ids as $id) {
                $b = $hitung['baris'][$id];
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

                $this->stok->catat(
                    $p,
                    StockMovementType::Purchase,
                    Uang::milliKeDesimal($b['milli']),
                    $pembelian,
                    "Pembelian {$pembelian->number} dari {$supplier->name}",
                    $user,
                );

                // Harga beli produk mengikuti pembelian terbaru (dasar harga modal penjualan berikutnya).
                // Memakai harga bersih per unit setelah diskon baris. Histori lama tidak berubah: tiap
                // baris pembelian/penjualan menyimpan harganya sendiri.
                if ($perbaruiHarga) {
                    $bersih = Uang::perUnit($b['total'], $b['milli']);
                    if ($bersih > 0 && $bersih !== Uang::sen($p->purchase_price)) {
                        // Tahap 11: catat perubahan harga ke audit log di sini.
                        $p->purchase_price = Uang::keDesimal($bersih);
                        $p->save();
                    }
                }
            }

            if ($hitung['dibayar'] > 0) {
                $this->pembayaran->bayarPembelian(
                    $pembelian,
                    $hitung['dibayar'],
                    PaymentMethod::from($data['payment_method']),
                    'Pembayaran saat pembelian',
                    $user,
                    $tanggal,
                );
            }

            return $pembelian->load(['supplier', 'items', 'payments']);
        });
    }

    /**
     * Batalkan pembelian (void): stok dikembalikan, pembayaran ditandai batal, dokumen tetap tersimpan.
     * Ditolak bila barangnya sudah terjual/keluar sehingga stok tidak cukup; gunakan Retur Pembelian.
     */
    public function batalkan(Purchase $pembelian, string $alasan, User $user): Purchase
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan pembatalan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($pembelian, $alasan, $user) {
            $terkunci = Purchase::whereKey($pembelian->getKey())->lockForUpdate()->with('items')->firstOrFail();

            if ($terkunci->status === PurchaseStatus::Voided) {
                throw new AturanBisnisException('Pembelian ini sudah dibatalkan.');
            }

            try {
                foreach ($terkunci->items->sortBy('product_id') as $item) {
                    $this->stok->catat(
                        Product::withTrashed()->findOrFail($item->product_id),
                        StockMovementType::Purchase,
                        '-' . $item->quantity,
                        $terkunci,
                        "Pembatalan pembelian {$terkunci->number}: {$alasan}",
                        $user,
                    );
                }
            } catch (AturanBisnisException $e) {
                throw new AturanBisnisException(
                    "Pembelian {$terkunci->number} tidak dapat dibatalkan karena sebagian barangnya sudah keluar. "
                    . $e->getMessage() . ' Gunakan Retur Pembelian untuk barang yang sudah terjual.'
                );
            }

            $sekarang = now();
            foreach ($terkunci->payments()->whereNull('voided_at')->get() as $pembayaran) {
                $pembayaran->forceFill(['voided_at' => $sekarang, 'voided_by' => $user->getKey(), 'void_reason' => "Pembelian dibatalkan: {$alasan}"])->save();
            }

            $terkunci->forceFill([
                'status' => PurchaseStatus::Voided,
                'voided_at' => $sekarang,
                'voided_by' => $user->getKey(),
                'void_reason' => $alasan,
            ])->save();

            // Harga beli produk tidak dikembalikan otomatis; ubah manual di master produk bila perlu.
            return $terkunci;
        });
    }

    /**
     * Hitung seluruh angka dengan bilangan bulat dan kumpulkan SEMUA kesalahan sekaligus.
     *
     * @return array{baris:array<int,array<string,int>>, subtotal:int, diskon:int, pajak:int, biaya:int, total:int, dibayar:int}
     */
    private function hitung(array $data): array
    {
        $errors = [];
        $baris = [];
        $subtotal = 0;

        foreach (array_values($data['items'] ?? []) as $i => $it) {
            $id = (int) $it['product_id'];
            $milli = Uang::milli($it['quantity'] ?? 0);
            $harga = Uang::sen($it['unit_price'] ?? 0);
            $diskon = Uang::sen($it['discount'] ?? 0);

            if (isset($baris[$id])) {
                $errors["items.{$i}.product_id"] = 'Produk yang sama tidak boleh muncul dua kali. Gabungkan jumlahnya.';

                continue;
            }

            if ($milli <= 0) {
                $errors["items.{$i}.quantity"] = 'Jumlah harus lebih dari nol.';

                continue;
            }

            $kotor = Uang::kali($milli, $harga);

            if ($diskon > $kotor) {
                $errors["items.{$i}.discount"] = 'Diskon melebihi nilai barang pada baris ini.';

                continue;
            }

            $total = $kotor - $diskon;
            $baris[$id] = ['milli' => $milli, 'harga' => $harga, 'diskon' => $diskon, 'total' => $total];
            $subtotal += $total;
        }

        if (! $baris && ! $errors) {
            $errors['items'] = 'Tambahkan minimal satu produk.';
        }

        $diskon = Uang::sen($data['discount'] ?? 0);
        $pajak = Uang::sen($data['tax'] ?? 0);
        $biaya = Uang::sen($data['other_cost'] ?? 0);

        if ($diskon > $subtotal) {
            $errors['discount'] = 'Diskon pembelian melebihi subtotal.';
        }

        $total = $subtotal - $diskon + $pajak + $biaya;

        if ($total > self::BATAS_TOTAL_SEN) {
            $errors['items'] = 'Total pembelian terlalu besar (maksimal Rp 99.999.999.999,99).';
        }

        $dibayar = Uang::sen($data['paid_amount'] ?? 0);

        if ($dibayar > $total) {
            $errors['paid_amount'] = 'Pembayaran melebihi total pembelian.';
        }

        if ($total - $dibayar > 0 && empty($data['due_date'])) {
            $errors['due_date'] = 'Jatuh tempo wajib diisi bila masih ada sisa hutang.';
        }

        if ($dibayar > 0 && empty($data['payment_method'])) {
            $errors['payment_method'] = 'Pilih metode pembayaran.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return compact('baris', 'subtotal', 'diskon', 'pajak', 'biaya', 'total', 'dibayar');
    }
}
