<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Uang;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SaleService
{
    /** Total maksimal Rp 99.999.999.999,99 (muat di decimal(15,2) dan aman untuk bilangan bulat). */
    private const BATAS_TOTAL_SEN = 9_999_999_999_999;

    public function __construct(
        private readonly StockService $stok,
        private readonly PaymentService $pembayaran,
        private readonly KodeService $kode,
    ) {
    }

    /**
     * Catat penjualan kasir. HARGA SELALU DIAMBIL DARI DATABASE: yang dipercaya dari klien hanya
     * produk, jumlah, diskon, pelanggan, dan pembayaran. Satu transaksi database: stok keluar,
     * dokumen, pembayaran, dan piutang tersimpan bersama atau dibatalkan bersama.
     *
     * @param array{
     *   customer_id?:?int, items:array<int, array{product_id:int, quantity:mixed, discount?:mixed}>,
     *   discount?:mixed, payment_method:string, paid_amount?:mixed, dp_method?:?string, note?:?string, held_id?:?int
     * } $data
     */
    public function buat(array $data, User $kasir): Sale
    {
        // Dua kasir yang menyimpan di detik yang sama bisa menghitung nomor yang sama. Batas unik database
        // menolak salah satunya; ulangi (nomor dihitung ulang) alih-alih menampilkan error ke kasir.
        for ($percobaan = 1; ; $percobaan++) {
            try {
                return $this->simpan($data, $kasir);
            } catch (UniqueConstraintViolationException $e) {
                if ($percobaan >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function simpan(array $data, User $kasir): Sale
    {
        $h = $this->hitung($data);

        return DB::transaction(function () use ($data, $h, $kasir) {
            $sekarang = now();

            $penjualan = new Sale([
                'number' => $this->kode->berikutnya(Sale::class, 'number', 'POS-' . $sekarang->format('Ymd') . '-', 4),
                'customer_id' => $h['pelanggan']->getKey(),
                'user_id' => $kasir->getKey(),
                'sold_at' => $sekarang,
                'subtotal' => Uang::keDesimal($h['kotor']),
                'item_discount' => Uang::keDesimal($h['diskonItem']),
                'discount' => Uang::keDesimal($h['diskonTrans']),
                'grand_total' => Uang::keDesimal($h['total']),
                'tendered_amount' => Uang::keDesimal($h['diterima']),
                'change_amount' => Uang::keDesimal($h['kembalian']),
                'payment_method' => $h['metode'],
                'note' => $data['note'] ?? null,
            ]);
            $penjualan->forceFill([
                'paid_amount' => '0.00',
                'initial_paid_amount' => '0.00',
                'balance' => Uang::keDesimal($h['total']),
                'payment_status' => PaymentStatus::dari(0, $h['total']),
                'status' => PurchaseStatus::Completed,
            ])->save();

            $ids = array_keys($h['baris']);
            sort($ids); // urutan tetap saat mengunci produk: mencegah deadlock antar kasir

            foreach ($ids as $id) {
                $b = $h['baris'][$id];
                $p = $b['produk'];

                $penjualan->items()->create([
                    'product_id' => $p->getKey(),
                    'product_name' => $p->name,
                    'sku' => $p->sku,
                    'unit_name' => $p->unit?->name ?? '-',
                    'quantity' => Uang::milliKeDesimal($b['milli']),
                    'unit_price' => Uang::keDesimal($b['harga']),
                    'cost_price' => Uang::keDesimal($b['modal']),
                    'discount' => Uang::keDesimal($b['diskon']),
                    'line_total' => Uang::keDesimal($b['total']),
                ]);

                // Menolak dengan "Stok tidak mencukupi." bila stok kurang, dan seluruh transaksi dibatalkan.
                $this->stok->catat(
                    $p,
                    StockMovementType::Sale,
                    '-' . Uang::milliKeDesimal($b['milli']),
                    $penjualan,
                    "Penjualan {$penjualan->number}",
                    $kasir,
                );
            }

            if ($h['metode'] === PaymentMethod::Receivable) {
                $pelanggan = Customer::whereKey($h['pelanggan']->getKey())->lockForUpdate()->firstOrFail();
                $pelanggan->forceFill([
                    'receivable_balance' => Uang::keDesimal(Uang::sen($pelanggan->receivable_balance) + $h['total']),
                ])->save();
            }

            if ($h['dibayar'] > 0) {
                $this->pembayaran->terimaPenjualan(
                    $penjualan,
                    $h['dibayar'],
                    $h['metodeBayar'],
                    $h['metode'] === PaymentMethod::Receivable ? 'Bayar di muka (DP)' : 'Pembayaran saat penjualan',
                    $kasir,
                    $sekarang->toDateString(),
                );
            }

            $penjualan->forceFill(['initial_paid_amount' => $penjualan->paid_amount])->save();

            // Keranjang yang ditahan dan baru saja dipanggil selesai: hapus.
            if (! empty($data['held_id'])) {
                HeldSale::where('user_id', $kasir->getKey())->whereKey($data['held_id'])->delete();
            }

            return $penjualan->load(['customer', 'items', 'payments']);
        });
    }

    /**
     * Batalkan penjualan (void): stok kembali, pembayaran ditandai batal, piutang pelanggan dikoreksi.
     * Ditolak bila sudah ada pelunasan piutang susulan: gunakan Retur Penjualan.
     */
    public function batalkan(Sale $penjualan, string $alasan, User $user): Sale
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan pembatalan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($penjualan, $alasan, $user) {
            $terkunci = Sale::whereKey($penjualan->getKey())->lockForUpdate()->with('items')->firstOrFail();

            if ($terkunci->status === PurchaseStatus::Voided) {
                throw new AturanBisnisException('Penjualan ini sudah dibatalkan.');
            }

            if (Uang::sen($terkunci->paid_amount) > Uang::sen($terkunci->initial_paid_amount)) {
                throw new AturanBisnisException(
                    "Penjualan {$terkunci->number} sudah menerima pelunasan piutang susulan, sehingga tidak dapat dibatalkan. Gunakan Retur Penjualan."
                );
            }

            foreach ($terkunci->items->sortBy('product_id') as $item) {
                $this->stok->catat(
                    Product::withTrashed()->findOrFail($item->product_id),
                    StockMovementType::Sale,
                    $item->quantity,
                    $terkunci,
                    "Pembatalan penjualan {$terkunci->number}: {$alasan}",
                    $user,
                );
            }

            // Sisa piutang penjualan ini hilang dari saldo pelanggan (DP sudah mengurangi saldo saat dibayar).
            if ($terkunci->payment_method === PaymentMethod::Receivable && Uang::sen($terkunci->balance) > 0) {
                $pelanggan = Customer::whereKey($terkunci->customer_id)->lockForUpdate()->firstOrFail();
                $pelanggan->forceFill([
                    'receivable_balance' => Uang::keDesimal(Uang::sen($pelanggan->receivable_balance) - Uang::sen($terkunci->balance)),
                ])->save();
            }

            $sekarang = now();
            foreach ($terkunci->payments()->whereNull('voided_at')->get() as $pembayaran) {
                $pembayaran->forceFill(['voided_at' => $sekarang, 'voided_by' => $user->getKey(), 'void_reason' => "Penjualan dibatalkan: {$alasan}"])->save();
            }

            $terkunci->forceFill([
                'status' => PurchaseStatus::Voided,
                'voided_at' => $sekarang,
                'voided_by' => $user->getKey(),
                'void_reason' => $alasan,
            ])->save();

            return $terkunci;
        });
    }

    /**
     * Hitung seluruh angka dengan bilangan bulat dan kumpulkan SEMUA kesalahan sekaligus.
     *
     * @return array<string, mixed>
     */
    private function hitung(array $data): array
    {
        $errors = [];
        $items = array_values($data['items'] ?? []);

        $produk = Product::aktif()->with('unit:id,name')
            ->whereIn('id', array_map(fn ($i) => (int) $i['product_id'], $items))
            ->get()->keyBy('id');

        $baris = [];
        $kotor = 0;
        $diskonItem = 0;

        foreach ($items as $i => $it) {
            $id = (int) $it['product_id'];

            if (! isset($produk[$id])) {
                $errors["items.{$i}.product_id"] = 'Produk tidak ditemukan atau sudah tidak aktif.';

                continue;
            }

            if (isset($baris[$id])) {
                $errors["items.{$i}.product_id"] = 'Produk yang sama tidak boleh muncul dua kali. Gabungkan jumlahnya.';

                continue;
            }

            $milli = Uang::milli($it['quantity'] ?? 0);

            if ($milli <= 0) {
                $errors["items.{$i}.quantity"] = 'Jumlah harus lebih dari nol.';

                continue;
            }

            // Harga dari database, bukan dari klien. Diskon tetap produk (Product::discount_percent)
            // diterapkan di sini supaya hasilnya sama persis dengan pratinjau "hargaBersih()" di layar kasir.
            $hargaPenuh = Uang::sen($produk[$id]->selling_price);
            $harga = Uang::kurangiPersen($hargaPenuh, $produk[$id]->discount_percent);
            $modal = Uang::sen($produk[$id]->purchase_price);

            try {
                $baruKotor = Uang::kali($milli, $harga);
            } catch (InvalidArgumentException) {
                $errors["items.{$i}.quantity"] = 'Nilai transaksi terlalu besar.';

                continue;
            }

            $diskon = Uang::sen($it['discount'] ?? 0);

            if ($diskon > $baruKotor) {
                $errors["items.{$i}.discount"] = 'Diskon melebihi nilai barang pada baris ini.';

                continue;
            }

            $baris[$id] = [
                'produk' => $produk[$id], 'milli' => $milli, 'harga' => $harga, 'modal' => $modal,
                'diskon' => $diskon, 'total' => $baruKotor - $diskon,
            ];
            $kotor += $baruKotor;
            $diskonItem += $diskon;
        }

        if (! $items) {
            $errors['items'] = 'Keranjang kosong.';
        }

        $bersih = $kotor - $diskonItem;
        $diskonTrans = Uang::sen($data['discount'] ?? 0);

        if ($diskonTrans > $bersih) {
            $errors['discount'] = 'Diskon transaksi melebihi total belanja.';
        }

        $total = $bersih - $diskonTrans;

        if ($total > self::BATAS_TOTAL_SEN) {
            $errors['items'] = 'Total transaksi terlalu besar (maksimal Rp 99.999.999.999,99).';
        }

        $pelanggan = ! empty($data['customer_id'])
            ? Customer::find($data['customer_id'])
            : Customer::umum();

        if (! $pelanggan) {
            $errors['customer_id'] = 'Pelanggan tidak ditemukan.';
            $pelanggan = Customer::umum();
        }

        $metode = PaymentMethod::from($data['payment_method']);
        $metodeBayar = $metode;
        $diterima = 0;
        $kembalian = 0;
        $dibayar = 0;

        if ($metode === PaymentMethod::Receivable) {
            if ($pelanggan->is_default) {
                $errors['customer_id'] = 'Penjualan piutang membutuhkan pelanggan terdaftar (bukan Umum).';
            }

            $dp = Uang::sen($data['paid_amount'] ?? 0);

            if ($dp >= $total) {
                $errors['paid_amount'] = 'Bayar di muka harus lebih kecil dari total. Bila lunas, pilih metode pembayaran biasa.';
            }

            $metodeBayar = PaymentMethod::from($data['dp_method'] ?? PaymentMethod::Cash->value);

            if ($metodeBayar === PaymentMethod::Receivable) {
                $errors['dp_method'] = 'Metode bayar di muka tidak boleh Piutang.';
            }

            $dibayar = $dp;
            $diterima = $dp;
        } elseif ($metode === PaymentMethod::Cash) {
            $diterima = Uang::sen($data['paid_amount'] ?? 0);

            if ($diterima < $total) {
                $errors['paid_amount'] = 'Uang yang diterima kurang dari total.';
            }

            $dibayar = $total;
            $kembalian = max(0, $diterima - $total);
        } else {
            // Non-tunai dibayar pas sebesar total.
            $dibayar = $total;
            $diterima = $total;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return compact('baris', 'kotor', 'diskonItem', 'diskonTrans', 'total', 'pelanggan', 'metode', 'metodeBayar', 'diterima', 'kembalian', 'dibayar');
    }
}
