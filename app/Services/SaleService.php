<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Events\SaleCompleted;
use App\Events\SaleVoided;
use App\Exceptions\AturanBisnisException;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\CashSessionService;
use App\Services\Settings\DocumentNumberService;
use App\Services\Settings\TaxSettingsService;
use App\Support\Format;
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
        private readonly DocumentNumberService $numbers,
        private readonly CashSessionService $sessions,
        private readonly TaxSettingsService $taxes,
    ) {}

    /**
     * Catat penjualan kasir. HARGA SELALU DIAMBIL DARI DATABASE: yang dipercaya dari klien hanya
     * produk, jumlah, diskon, pelanggan, dan pembayaran. Satu transaksi database: stok keluar,
     * dokumen, pembayaran, dan piutang tersimpan bersama atau dibatalkan bersama.
     *
     * Gudang penjualan = gudang sesi kasir yang terbuka, atau gudang bawaan cabang aktif bila tanpa sesi.
     *
     * Pembayaran, dua bentuk:
     *   - payments: [{method, amount}, ...] untuk satu atau beberapa metode (split payment). Amount tunai =
     *     uang yang diserahkan (boleh lebih, kembalian dari tunai). payment_method = 'receivable' berarti
     *     sisa yang belum dibayar menjadi piutang pelanggan.
     *   - bentuk lama: payment_method + paid_amount (+ dp_method untuk piutang), satu metode.
     *
     * @param array{
     *   customer_id?:?int, items:array<int, array{product_id:int, quantity:mixed, discount?:mixed}>,
     *   discount?:mixed, payment_method?:?string, paid_amount?:mixed, dp_method?:?string,
     *   payments?:array<int, array{method:string, amount:mixed}>, note?:?string, held_id?:?int
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

        $penjualan = DB::transaction(function () use ($data, $h, $kasir) {
            $sekarang = now();

            // Sesi dikunci sampai transaksi selesai: sesi tidak bisa ditutup di tengah penyimpanan.
            $sesi = $this->sessions->lockCurrent($kasir);

            if (! $sesi && $this->sessions->isRequired()) {
                throw new AturanBisnisException('Buka sesi kasir dulu sebelum menjual.');
            }

            $gudang = $sesi ? Warehouse::withTrashed()->findOrFail($sesi->warehouse_id) : $this->sessions->branchWarehouse();

            $penjualan = new Sale([
                'number' => $this->numbers->next(DocumentType::Sale, $sekarang),
                'warehouse_id' => $gudang->getKey(),
                'cash_session_id' => $sesi?->getKey(),
                'customer_id' => $h['pelanggan']->getKey(),
                'user_id' => $kasir->getKey(),
                'sold_at' => $sekarang,
                'subtotal' => Uang::keDesimal($h['kotor']),
                'item_discount' => Uang::keDesimal($h['diskonItem']),
                'discount' => Uang::keDesimal($h['diskonTrans']),
                'tax_rate' => $h['pajak']['rate'],
                'tax_inclusive' => $h['pajak']['inclusive'],
                'tax' => Uang::keDesimal($h['pajak']['sen']),
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

                // Menolak dengan "Stok tidak mencukupi." bila stok gudang kurang, dan seluruh transaksi dibatalkan.
                $this->stok->catat(
                    $p,
                    StockMovementType::Sale,
                    '-'.Uang::milliKeDesimal($b['milli']),
                    $penjualan,
                    "Penjualan {$penjualan->number}",
                    $kasir,
                    $gudang,
                );
            }

            if ($h['metode'] === PaymentMethod::Receivable) {
                $pelanggan = Customer::whereKey($h['pelanggan']->getKey())->lockForUpdate()->firstOrFail();
                $pelanggan->forceFill([
                    'receivable_balance' => Uang::keDesimal(Uang::sen($pelanggan->receivable_balance) + $h['total']),
                ])->save();
            }

            $catatan = $h['metode'] === PaymentMethod::Receivable ? 'Bayar di muka (DP)' : 'Pembayaran saat penjualan';
            foreach ($h['pembayaran'] as $bayar) {
                $this->pembayaran->terimaPenjualan(
                    $penjualan,
                    $bayar['sen'],
                    $bayar['metode'],
                    $catatan,
                    $kasir,
                    $sekarang->toDateString(),
                    $sesi,
                    atCheckout: true,
                );
            }

            $penjualan->forceFill(['initial_paid_amount' => $penjualan->paid_amount])->save();

            // Keranjang yang ditahan dan baru saja dipanggil selesai: hapus.
            if (! empty($data['held_id'])) {
                HeldSale::where('user_id', $kasir->getKey())->whereKey($data['held_id'])->delete();
            }

            SaleCompleted::dispatch($penjualan);

            return $penjualan;
        });

        return $penjualan->load(['customer', 'items', 'payments']);
    }

    /**
     * Batalkan penjualan (void): stok kembali, pembayaran ditandai batal, piutang pelanggan dikoreksi.
     * Ditolak bila sudah ada pelunasan piutang susulan atau retur: gunakan Retur Penjualan.
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

            if ($terkunci->returns()->exists()) {
                throw new AturanBisnisException(
                    "Penjualan {$terkunci->number} sudah punya retur, sehingga tidak dapat dibatalkan. Hapus draft retur, atau retur sisa barangnya."
                );
            }

            // Stok kembali ke gudang asal penjualan.
            $gudang = Warehouse::withTrashed()->find($terkunci->warehouse_id) ?? $this->stok->defaultWarehouse();

            foreach ($terkunci->items->sortBy('product_id') as $item) {
                $this->stok->catat(
                    Product::withTrashed()->findOrFail($item->product_id),
                    StockMovementType::Sale,
                    $item->quantity,
                    $terkunci,
                    "Pembatalan penjualan {$terkunci->number}: {$alasan}",
                    $user,
                    $gudang,
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

            activity('penjualan')->performedOn($terkunci)->causedBy($user)->event('voided')
                ->withProperties(['alasan' => $alasan, 'total' => $terkunci->grand_total])
                ->log("Penjualan {$terkunci->number} dibatalkan");

            SaleVoided::dispatch($terkunci);

            return $terkunci;
        });
    }

    /**
     * Pajak penjualan dari Pengaturan → Pajak, dihitung dari total setelah diskon (sen).
     *   - Harga termasuk pajak: total tetap, pajak = bagian di dalamnya = dasar x tarif / (100 + tarif).
     *   - Harga belum termasuk pajak: pajak = dasar x tarif / 100, ditambahkan ke total.
     * Dibulatkan setengah ke atas, sama dengan pratinjau di layar kasir (resources/js/pos/kasir.js).
     *
     * @return array{rate: string, inclusive: bool, sen: int}
     */
    public function pajak(int $dasar): array
    {
        $pengaturan = $this->taxes->get();
        $tarifBp = $pengaturan['enabled'] ? Uang::sen($pengaturan['rate']) : 0; // 11% -> 1100 basis poin

        if ($tarifBp <= 0 || $dasar <= 0) {
            return ['rate' => '0.00', 'inclusive' => false, 'sen' => 0];
        }

        $sen = $pengaturan['inclusive']
            ? $dasar - intdiv($dasar * 10000 * 2 + (10000 + $tarifBp), 2 * (10000 + $tarifBp))
            : intdiv($dasar * $tarifBp * 2 + 10000, 20000);

        return ['rate' => Uang::keDesimal($tarifBp), 'inclusive' => $pengaturan['inclusive'], 'sen' => $sen];
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

        $dasar = max(0, $bersih - $diskonTrans);
        $pajak = $dasar <= self::BATAS_TOTAL_SEN ? $this->pajak($dasar) : ['rate' => '0.00', 'inclusive' => false, 'sen' => 0];
        $total = $pajak['inclusive'] ? $dasar : $dasar + $pajak['sen'];

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

        $bayar = $this->pembayaran($data, $total, $pelanggan, $errors);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return compact('baris', 'kotor', 'diskonItem', 'diskonTrans', 'pajak', 'total', 'pelanggan') + $bayar;
    }

    /**
     * Ubah isian pembayaran (bentuk baru atau lama) menjadi baris pembayaran yang benar-benar diterima.
     *
     * @param  array<string, string>  $errors
     * @return array{metode: PaymentMethod, pembayaran: list<array{metode: PaymentMethod, sen: int}>, diterima: int, kembalian: int}
     */
    private function pembayaran(array $data, int $total, Customer $pelanggan, array &$errors): array
    {
        $piutang = ($data['payment_method'] ?? null) === PaymentMethod::Receivable->value;
        $bentukBaru = isset($data['payments']) && is_array($data['payments']);
        $kunci = $bentukBaru ? 'payments' : 'paid_amount';

        if ($bentukBaru) {
            $baris = array_map(fn (array $p) => [
                'metode' => PaymentMethod::from($p['method']),
                'sen' => Uang::sen($p['amount'] ?? 0),
            ], array_values($data['payments']));
        } else {
            $metode = PaymentMethod::from($data['payment_method'] ?? PaymentMethod::Cash->value);
            $baris = match (true) {
                $piutang => Uang::sen($data['paid_amount'] ?? 0) > 0
                    ? [['metode' => PaymentMethod::from($data['dp_method'] ?? PaymentMethod::Cash->value), 'sen' => Uang::sen($data['paid_amount'])]]
                    : [],
                $metode === PaymentMethod::Cash => [['metode' => $metode, 'sen' => Uang::sen($data['paid_amount'] ?? 0)]],
                // Non-tunai (bentuk lama) dibayar pas sebesar total.
                default => [['metode' => $metode, 'sen' => $total]],
            };
        }

        foreach ($baris as $b) {
            if (in_array($b['metode'], [PaymentMethod::Receivable, PaymentMethod::Mixed], true)) {
                $errors[$bentukBaru ? 'payments' : 'dp_method'] = 'Piutang bukan metode pembayaran. Pilih metode yang dipakai pelanggan membayar.';

                return ['metode' => PaymentMethod::Cash, 'pembayaran' => [], 'diterima' => 0, 'kembalian' => 0];
            }
        }

        $tunai = array_sum(array_map(fn ($b) => $b['metode'] === PaymentMethod::Cash ? $b['sen'] : 0, $baris));
        $nonTunai = array_sum(array_map(fn ($b) => $b['metode'] === PaymentMethod::Cash ? 0 : $b['sen'], $baris));

        if ($nonTunai > $total) {
            $errors[$kunci] = 'Pembayaran non-tunai melebihi total. Kembalian hanya bisa dari uang tunai.';
        }

        $tunaiDipakai = max(0, min($tunai, $total - $nonTunai));
        $kembalian = $tunai - $tunaiDipakai;
        $sisa = $total - min($total, $nonTunai) - $tunaiDipakai;

        if ($piutang) {
            if ($pelanggan->is_default) {
                $errors['customer_id'] = 'Penjualan piutang membutuhkan pelanggan terdaftar (bukan Umum).';
            }

            if ($sisa <= 0) {
                $errors[$kunci] = 'Bayar di muka harus lebih kecil dari total. Bila lunas, pilih metode pembayaran biasa.';
            }
        } elseif ($sisa > 0) {
            $errors[$kunci] = $bentukBaru
                ? 'Pembayaran masih kurang '.Format::rupiah(Uang::keDesimal($sisa)).'.'
                : 'Uang yang diterima kurang dari total.';
        }

        // Baris yang benar-benar diterima: tunai dipotong kembalian, baris nol dilewati.
        $diterima = [];
        foreach ($baris as $b) {
            $sen = $b['metode'] === PaymentMethod::Cash ? 0 : $b['sen'];
            if ($sen > 0) {
                $diterima[] = ['metode' => $b['metode'], 'sen' => $sen];
            }
        }
        if ($tunaiDipakai > 0) {
            $diterima[] = ['metode' => PaymentMethod::Cash, 'sen' => $tunaiDipakai];
        }

        $metodeDipakai = array_values(array_unique(array_map(fn ($b) => $b['metode']->value, $diterima)));
        $metode = match (true) {
            $piutang => PaymentMethod::Receivable,
            count($metodeDipakai) > 1 => PaymentMethod::Mixed,
            count($metodeDipakai) === 1 => PaymentMethod::from($metodeDipakai[0]),
            default => $baris[0]['metode'] ?? PaymentMethod::Cash,
        };

        return [
            'metode' => $metode,
            'pembayaran' => $diterima,
            'diterima' => $tunai + $nonTunai,
            'kembalian' => $kembalian,
        ];
    }
}
