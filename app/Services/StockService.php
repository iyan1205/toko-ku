<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Events\StockAdjusted;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Scopes\BranchScope;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockMovementLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Accounting\FiscalPeriodService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA pintu untuk mengubah stok (warehouse_stocks, products.stock, lot, nomor seri).
 *
 * Setiap perubahan, dalam satu transaksi database:
 *   1. kunci baris produk, lalu baris stok gudangnya (urutan selalu sama: mencegah deadlock),
 *   2. tolak bila stok GUDANG itu menjadi minus (kecuali pos.allow_negative_stock),
 *   3. ubah stok gudang dan products.stock (total semua gudang) bersamaan,
 *   4. stok masuk membuat lot (batch, kedaluwarsa, biaya per unit); stok keluar memakai lot
 *      kedaluwarsa terdekat dulu lalu yang masuk lebih dulu (FEFO/FIFO),
 *   5. hitung biaya mutasi (Pengaturan → Inventori: FIFO = biaya lot yang terpakai, rata-rata =
 *      nilai gudang / jumlah) dan ubah nilai persediaan gudang (warehouse_stocks.value),
 *   6. tulis kartu stok (stock_movements) dengan gudang, biaya, dan lot-nya.
 *
 * Opsi ($opsi):
 *   unit_cost      biaya per unit (sen) stok masuk; tanpa ini = biaya rata-rata gudang / harga beli produk
 *   batch_number, expiry_date   batch stok masuk
 *   lot_id         stok keluar dari lot ini dulu (misal batch yang dipilih)
 *   reverse_of     Collection<StockMovement> yang dibalik (pembatalan, retur): lot dan biaya aslinya dipakai
 *   from_movement  StockMovement keluar yang dipindah (transfer masuk): batch, kedaluwarsa, biaya ikut
 *   serials        list nomor seri (produk track_serials)
 *
 * Pemanggil yang tidak menyebut gudang memakai gudang utama.
 */
class StockService
{
    private ?Warehouse $defaultWarehouse = null;

    /**
     * @param  float|int|string  $jumlah  perubahan BERTANDA: positif = masuk, negatif = keluar
     * @param  array{unit_cost?: int, batch_number?: ?string, expiry_date?: ?string, lot_id?: ?int, reverse_of?: Collection<int, StockMovement>|StockMovement|null, from_movement?: ?StockMovement, serials?: list<string>}  $opsi
     */
    public function catat(
        Product $produk,
        StockMovementType $jenis,
        float|int|string $jumlah,
        ?Model $referensi = null,
        ?string $catatan = null,
        ?User $user = null,
        ?Warehouse $gudang = null,
        array $opsi = [],
    ): StockMovement {
        $delta = round((float) $jumlah, 3);

        if ($delta == 0.0) {
            throw new AturanBisnisException('Jumlah perubahan stok tidak boleh nol.');
        }

        $gudang ??= $this->defaultWarehouse();

        return DB::transaction(function () use ($produk, $jenis, $delta, $referensi, $catatan, $user, $gudang, $opsi) {
            // Kunci baris produk: dua kasir yang menjual barang yang sama tidak boleh saling menimpa.
            $terkunci = Product::withTrashed()->whereKey($produk->getKey())->lockForUpdate()->firstOrFail();
            $stokGudang = $this->lockWarehouseStock($gudang, $terkunci);

            $sebelum = round((float) $stokGudang->quantity, 3);
            $sesudah = round($sebelum + $delta, 3);

            if ($sesudah < 0 && ! config('pos.allow_negative_stock')) {
                throw new AturanBisnisException(sprintf(
                    'Stok tidak mencukupi. %s di %s: tersedia %s, diminta %s.',
                    $terkunci->name,
                    $gudang->name,
                    Format::jumlah($sebelum),
                    Format::jumlah(abs($delta)),
                ));
            }
            if ($terkunci->track_serials && floor(abs($delta)) != abs($delta)) {
                throw new AturanBisnisException("{$terkunci->name} memakai nomor seri, jadi jumlahnya harus bilangan bulat.");
            }

            $reverseOf = $this->movements($opsi['reverse_of'] ?? null);
            [$alokasi, $biaya] = $delta > 0
                ? $this->masuk($terkunci, $gudang, $stokGudang, Uang::milli($delta), $sebelum, $reverseOf, $opsi)
                : $this->keluar($terkunci, $gudang, $stokGudang, $jenis, Uang::milli(abs($delta)), $sebelum, $reverseOf, $opsi);

            $nilai = Uang::sen($stokGudang->value) + ($delta > 0 ? $biaya : -$biaya);
            if ($sesudah == 0.0 && $reverseOf->isEmpty()) {
                $nilai = 0; // stok habis: tidak ada sisa nilai karena pembulatan
            }
            $stokGudang->forceFill(['quantity' => $sesudah, 'value' => Uang::keDesimal($nilai)])->save();
            $terkunci->forceFill(['stock' => round((float) $terkunci->stock + $delta, 3)])->save();

            // Sinkronkan instance milik pemanggil agar tidak menampilkan stok basi.
            $produk->setAttribute('stock', $terkunci->stock);
            $produk->syncOriginalAttribute('stock');

            $mutasi = StockMovement::create([
                'warehouse_id' => $gudang->getKey(),
                'product_id' => $terkunci->getKey(),
                'type' => $jenis,
                'reference_type' => $referensi?->getMorphClass(),
                'reference_id' => $referensi?->getKey(),
                'quantity' => $delta,
                'stock_before' => $sebelum,
                'stock_after' => $sesudah,
                'note' => $catatan,
                'user_id' => $user?->getKey(),
                'unit_cost' => Uang::keDesimal(Uang::perUnit($biaya, Uang::milli(abs($delta)))),
                'total_cost' => Uang::keDesimal($delta > 0 ? $biaya : -$biaya),
            ]);

            foreach ($alokasi as $a) {
                StockMovementLot::query()->insert([
                    'stock_movement_id' => $mutasi->id, 'stock_lot_id' => $a['lot_id'],
                    'quantity' => Uang::milliKeDesimal($delta > 0 ? $a['milli'] : -$a['milli']), 'unit_cost' => Uang::keDesimal($a['cost']),
                ]);
                if ($delta > 0 && $a['lot_id'] !== null) {
                    StockLot::query()->whereKey($a['lot_id'])->whereNull('source_movement_id')->update(['source_movement_id' => $mutasi->id]);
                }
            }

            if ($terkunci->track_serials) {
                $this->serials($terkunci, $gudang, $mutasi, (int) abs($delta), $alokasi, $reverseOf, $opsi);
            }

            return $mutasi;
        });
    }

    /**
     * Seperti catat(), tetapi produk paket dipecah ke komponennya (jumlah × isi per paket). Untuk pembalik
     * (pembatalan/retur) beri $balikDokumen = dokumen asal: mutasi asal tiap produk dibalik dari lot aslinya.
     *
     * @return Collection<int, StockMovement>
     */
    public function catatBarang(
        Product $produk,
        StockMovementType $jenis,
        float|int|string $jumlah,
        ?Model $referensi = null,
        ?string $catatan = null,
        ?User $user = null,
        ?Warehouse $gudang = null,
        array $opsi = [],
        ?Model $balikDokumen = null,
    ): Collection {
        $delta = round((float) $jumlah, 3);
        $target = $produk->is_bundle
            ? $produk->bundleComponents()->map(fn (array $k) => [$k['product'], round($delta * $k['quantity'], 3)])
            : collect([[$produk, $delta]]);

        if ($produk->is_bundle && $target->isEmpty()) {
            throw new AturanBisnisException("Paket {$produk->name} belum punya komponen. Atur komponennya di resep produk itu.");
        }

        return DB::transaction(fn () => $target->sortBy(fn ($t) => $t[0]->id)->values()->map(function (array $t) use ($jenis, $referensi, $catatan, $user, $gudang, $opsi, $balikDokumen) {
            [$barang, $qty] = $t;
            $o = $opsi;
            if ($balikDokumen !== null) {
                $o['reverse_of'] = self::mutasiDokumen($balikDokumen, $barang->id, $qty > 0 ? -1 : 1);
            }

            return $this->catat($barang, $jenis, $qty, $referensi, $catatan, $user, $gudang, $o);
        }));
    }

    /**
     * Penyesuaian manual. Penyesuaian bisa menambah ('in') atau mengurangi ('out');
     * barang rusak dan hilang selalu mengurangi.
     *
     * @param  array{unit_cost?: int, batch_number?: ?string, expiry_date?: ?string, lot_id?: ?int, serials?: list<string>}  $opsi
     */
    public function sesuaikan(
        Product $produk,
        StockMovementType $jenis,
        string $arah,
        float|int|string $jumlah,
        string $catatan,
        User $user,
        ?Warehouse $gudang = null,
        array $opsi = [],
    ): StockMovement {
        if (! in_array($jenis, StockMovementType::manual(), true)) {
            throw new AturanBisnisException('Jenis mutasi ini tidak dapat dibuat manual.');
        }

        app(FiscalPeriodService::class)->ensureOpen(now());

        $besar = abs(round((float) $jumlah, 3));
        $tambah = $jenis === StockMovementType::Adjustment && $arah === 'in';

        $mutasi = $this->catat($produk, $jenis, $tambah ? $besar : -$besar, null, trim($catatan), $user, $gudang, $opsi);
        StockAdjusted::dispatch($mutasi);

        return $mutasi;
    }

    /**
     * Mutasi stok milik satu dokumen untuk satu produk ($arah: 1 = masuk, -1 = keluar), untuk dibalik.
     *
     * @return Collection<int, StockMovement>
     */
    public static function mutasiDokumen(Model $dokumen, int $productId, int $arah): Collection
    {
        // Lintas cabang: transfer antar cabang punya mutasi di dua cabang.
        return StockMovement::query()->withoutGlobalScope(BranchScope::class)
            ->where('reference_type', $dokumen->getMorphClass())->where('reference_id', $dokumen->getKey())
            ->where('product_id', $productId)->where('quantity', $arah > 0 ? '>' : '<', 0)
            ->orderBy('id')->get();
    }

    /**
     * Opsi batch/seri dari isian baris formulir: batch_number, expiry_date, serials (dipisah koma/baris baru).
     *
     * @param  array<string, mixed>  $baris
     * @return array{batch_number?: string, expiry_date?: string, serials?: list<string>}
     */
    public static function opsiBaris(array $baris): array
    {
        $serials = preg_split('/[\s,;]+/', (string) ($baris['serials'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        return array_filter([
            'batch_number' => filled($baris['batch_number'] ?? null) ? trim((string) $baris['batch_number']) : null,
            'expiry_date' => filled($baris['expiry_date'] ?? null) ? (string) $baris['expiry_date'] : null,
            'serials' => $serials ?: null,
        ]);
    }

    public function defaultWarehouse(): Warehouse
    {
        return $this->defaultWarehouse ??= Warehouse::default();
    }

    /** fifo | average (Pengaturan → Inventori). */
    public function costingMethod(): string
    {
        return setting('inventory.costing_method', 'average') === 'fifo' ? 'fifo' : 'average';
    }

    /** Biaya rata-rata per unit (sen) produk di gudang, atau harga beli produk bila stok kosong. */
    public function averageCost(Product $produk, Warehouse $gudang): int
    {
        $row = WarehouseStock::query()->where('warehouse_id', $gudang->getKey())->where('product_id', $produk->getKey())->first();

        return $row && (float) $row->quantity > 0 && Uang::sen($row->value) > 0
            ? Uang::perUnit(Uang::sen($row->value), Uang::milli($row->quantity))
            : Uang::sen($produk->purchase_price);
    }

    /**
     * Stok masuk: kembalikan ke lot asal (pembalik), salin lot dari gudang asal (transfer), atau lot baru.
     *
     * @return array{0: list<array{lot_id: ?int, milli: int, cost: int}>, 1: int}
     */
    private function masuk(Product $produk, Warehouse $gudang, WarehouseStock $stok, int $milli, float $sebelum, Collection $reverseOf, array $opsi): array
    {
        $alokasi = [];
        $sisa = $milli;

        if ($reverseOf->isNotEmpty()) {
            // Kembalikan ke lot yang dipakai mutasi asal, mulai dari yang terakhir terpakai.
            $asal = StockMovementLot::query()->whereIn('stock_movement_id', $reverseOf->pluck('id'))
                ->where('quantity', '<', 0)->orderByDesc('id')->lockForUpdate()->get();
            foreach ($asal as $a) {
                $bisa = Uang::milli(abs((float) $a->quantity)) - Uang::milli($a->reversed_quantity);
                $ambil = min($sisa, $bisa);
                if ($ambil <= 0) {
                    continue;
                }
                $lotId = $a->stock_lot_id !== null && StockLot::query()->whereKey($a->stock_lot_id)->exists()
                    ? $a->stock_lot_id
                    : $this->lotBaru($produk, $gudang, 0, Uang::sen($a->unit_cost), [], now())->id;
                DB::table('stock_lots')->where('id', $lotId)->increment('quantity', Uang::milliKeDesimal($ambil));
                DB::table('stock_movement_lots')->where('id', $a->id)->increment('reversed_quantity', Uang::milliKeDesimal($ambil));
                $alokasi[] = ['lot_id' => $lotId, 'milli' => $ambil, 'cost' => Uang::sen($a->unit_cost)];
                $sisa -= $ambil;
                if ($sisa === 0) {
                    break;
                }
            }
        } elseif (($dari = $opsi['from_movement'] ?? null) instanceof StockMovement) {
            // Transfer: batch, kedaluwarsa, tanggal masuk, dan biaya ikut pindah.
            foreach ($dari->lots()->with('lot')->get() as $a) {
                $ambil = min($sisa, Uang::milli(abs((float) $a->quantity)));
                if ($ambil <= 0) {
                    continue;
                }
                $lot = $this->lotBaru($produk, $gudang, $ambil, Uang::sen($a->unit_cost), [
                    'batch_number' => $a->lot?->batch_number, 'expiry_date' => $a->lot?->expiry_date?->toDateString(),
                ], $a->lot?->received_at ?? now());
                $alokasi[] = ['lot_id' => $lot->id, 'milli' => $ambil, 'cost' => Uang::sen($a->unit_cost)];
                $sisa -= $ambil;
            }
        }

        if ($sisa > 0) {
            $biayaUnit = $opsi['unit_cost'] ?? $this->averageCost($produk, $gudang);
            // Stok sedang minus: bagian yang menutup kekurangan tidak menjadi lot.
            $kurang = $sebelum < 0 ? min($sisa, Uang::milli(abs($sebelum))) : 0;
            if ($kurang > 0) {
                $alokasi[] = ['lot_id' => null, 'milli' => $kurang, 'cost' => $biayaUnit];
                $sisa -= $kurang;
            }
            if ($sisa > 0) {
                $lot = $this->lotBaru($produk, $gudang, $sisa, $biayaUnit, $opsi, now());
                $alokasi[] = ['lot_id' => $lot->id, 'milli' => $sisa, 'cost' => $biayaUnit];
            }
        }

        return [$alokasi, array_sum(array_map(fn ($a) => Uang::kali($a['milli'], $a['cost']), $alokasi))];
    }

    /**
     * Stok keluar: pakai lot (lot pilihan / lot mutasi asal yang dibatalkan dulu, lalu FEFO/FIFO) dan hitung biayanya.
     *
     * @return array{0: list<array{lot_id: ?int, milli: int, cost: int}>, 1: int}
     */
    private function keluar(Product $produk, Warehouse $gudang, WarehouseStock $stok, StockMovementType $jenis, int $milli, float $sebelum, Collection $reverseOf, array $opsi): array
    {
        $prioritas = [];
        if ($reverseOf->isNotEmpty()) {
            $prioritas = StockMovementLot::query()->whereIn('stock_movement_id', $reverseOf->pluck('id'))->where('quantity', '>', 0)
                ->whereNotNull('stock_lot_id')->pluck('stock_lot_id')->all();
        } elseif (! empty($opsi['lot_id'])) {
            $prioritas = [(int) $opsi['lot_id']];
        }

        $lots = StockLot::query()->where('product_id', $produk->getKey())->where('warehouse_id', $gudang->getKey())->available()
            ->consumeOrder()->lockForUpdate()->get();
        $urut = $lots->sortBy(fn (StockLot $l) => ($i = array_search($l->id, $prioritas, true)) === false ? PHP_INT_MAX : $i)->values();

        // Batch kedaluwarsa tidak dijual (Pengaturan → Inventori), kecuali dipilih/ dibalik secara khusus.
        if ($jenis === StockMovementType::Sale && $produk->track_batches && $prioritas === [] && setting('inventory.block_expired_sales', '1') === '1') {
            $layak = $urut->reject(fn (StockLot $l) => $l->isExpired());
            $tersedia = $layak->sum(fn (StockLot $l) => Uang::milli($l->quantity));
            if ($tersedia < $milli && $layak->count() < $urut->count()) {
                throw new AturanBisnisException(sprintf('%s di %s yang belum kedaluwarsa tinggal %s.', $produk->name, $gudang->name, Format::jumlah($tersedia / 1000)));
            }
            $urut = $layak->values();
        }

        $alokasi = [];
        $sisa = $milli;
        foreach ($urut as $lot) {
            $ambil = min($sisa, Uang::milli($lot->quantity));
            if ($ambil <= 0) {
                continue;
            }
            DB::table('stock_lots')->where('id', $lot->id)->decrement('quantity', Uang::milliKeDesimal($ambil));
            $alokasi[] = ['lot_id' => $lot->id, 'milli' => $ambil, 'cost' => Uang::sen($lot->unit_cost)];
            $sisa -= $ambil;
            if ($sisa === 0) {
                break;
            }
        }

        $biayaRata = $sebelum > 0 && Uang::sen($stok->value) > 0 ? Uang::perUnit(Uang::sen($stok->value), Uang::milli($sebelum)) : Uang::sen($produk->purchase_price);
        if ($sisa > 0) {
            // Stok minus (diizinkan pengaturan): tanpa lot, biaya rata-rata/harga beli.
            $alokasi[] = ['lot_id' => null, 'milli' => $sisa, 'cost' => $alokasi === [] ? $biayaRata : end($alokasi)['cost']];
        }

        $biayaLot = array_sum(array_map(fn ($a) => Uang::kali($a['milli'], $a['cost']), $alokasi));
        if ($reverseOf->isNotEmpty() || $this->costingMethod() === 'fifo') {
            return [$alokasi, $biayaLot];
        }

        // Rata-rata tertimbang: biaya = jumlah × nilai/jumlah gudang; mengambil semua = seluruh nilai sisa.
        $nilai = Uang::sen($stok->value);
        $ada = Uang::milli($sebelum);
        $biaya = match (true) {
            $sebelum <= 0 || $nilai <= 0 => Uang::kali($milli, $biayaRata),
            $milli >= $ada => $nilai + Uang::kali($milli - $ada, $biayaRata),
            default => Uang::kaliBagi($milli, $nilai, $ada),
        };

        return [array_map(fn ($a) => ['cost' => $biayaRata] + $a, $alokasi), $biaya];
    }

    private function lotBaru(Product $produk, Warehouse $gudang, int $milli, int $biayaUnit, array $opsi, $diterima): StockLot
    {
        $lot = new StockLot;
        $lot->forceFill([
            'product_id' => $produk->getKey(),
            'warehouse_id' => $gudang->getKey(),
            'batch_number' => filled($opsi['batch_number'] ?? null) ? mb_strtoupper(trim($opsi['batch_number'])) : null,
            'expiry_date' => $opsi['expiry_date'] ?? null,
            'received_at' => $diterima,
            'unit_cost' => Uang::keDesimal($biayaUnit),
            'quantity_in' => Uang::milliKeDesimal($milli),
            'quantity' => Uang::milliKeDesimal($milli),
        ])->save();

        return $lot;
    }

    /**
     * Nomor seri: masuk = baru/kembali tersedia, keluar = ditandai keluar. Tanpa daftar seri, yang dipakai
     * adalah seri dari mutasi asal (pembalik/transfer) atau seri tersedia yang paling lama.
     *
     * @param  list<array{lot_id: ?int, milli: int, cost: int}>  $alokasi
     */
    private function serials(Product $produk, Warehouse $gudang, StockMovement $mutasi, int $jumlah, array $alokasi, Collection $reverseOf, array $opsi): void
    {
        $daftar = collect($opsi['serials'] ?? [])->map(fn ($s) => trim((string) $s))->filter()->unique()->values();
        if ($daftar->isNotEmpty() && $daftar->count() !== $jumlah) {
            throw new AturanBisnisException("Jumlah nomor seri {$produk->name} ({$daftar->count()}) harus sama dengan jumlah barang ({$jumlah}).");
        }
        $lotId = collect($alokasi)->firstWhere('lot_id', '!=', null)['lot_id'] ?? null;
        $masuk = (float) $mutasi->quantity > 0;
        $q = ProductSerial::query()->where('product_id', $produk->getKey())->lockForUpdate();

        if ($masuk) {
            $sumber = match (true) {
                $daftar->isNotEmpty() => null,
                $reverseOf->isNotEmpty() => (clone $q)->whereIn('out_movement_id', $reverseOf->pluck('id'))->where('status', 'out')->limit($jumlah)->get(),
                ($opsi['from_movement'] ?? null) instanceof StockMovement => (clone $q)->where('out_movement_id', $opsi['from_movement']->id)->where('status', 'out')->limit($jumlah)->get(),
                default => collect(),
            };
            if ($sumber === null) {
                foreach ($daftar as $nomor) {
                    $serial = (clone $q)->where('serial_number', $nomor)->first() ?? new ProductSerial;
                    if ($serial->exists && $serial->status === 'in_stock') {
                        throw new AturanBisnisException("Nomor seri {$nomor} ({$produk->name}) sudah tercatat di stok.");
                    }
                    $serial->forceFill(['product_id' => $produk->getKey(), 'serial_number' => $nomor, 'warehouse_id' => $gudang->getKey(), 'stock_lot_id' => $lotId,
                        'status' => 'in_stock', 'in_movement_id' => $mutasi->id, 'out_movement_id' => null])->save();
                }
            } else {
                $sumber->each(fn (ProductSerial $s) => $s->forceFill(['warehouse_id' => $gudang->getKey(), 'stock_lot_id' => $lotId, 'status' => 'in_stock', 'in_movement_id' => $mutasi->id, 'out_movement_id' => null])->save());
            }

            return;
        }

        $tersedia = (clone $q)->where('warehouse_id', $gudang->getKey())->where('status', 'in_stock');
        if ($daftar->isNotEmpty()) {
            $dipilih = (clone $tersedia)->whereIn('serial_number', $daftar)->get();
            $hilang = $daftar->diff($dipilih->pluck('serial_number'));
            if ($hilang->isNotEmpty()) {
                throw new AturanBisnisException("Nomor seri {$hilang->join(', ')} ({$produk->name}) tidak ada di stok {$gudang->name}.");
            }
        } else {
            $dipilih = $reverseOf->isNotEmpty()
                ? (clone $tersedia)->whereIn('in_movement_id', $reverseOf->pluck('id'))->limit($jumlah)->get()
                : collect();
            if ($dipilih->count() < $jumlah) {
                $dipilih = $dipilih->concat((clone $tersedia)->whereNotIn('id', $dipilih->pluck('id'))->orderBy('id')->limit($jumlah - $dipilih->count())->get());
            }
        }
        $dipilih->each(fn (ProductSerial $s) => $s->forceFill(['status' => 'out', 'out_movement_id' => $mutasi->id])->save());
    }

    /** @return Collection<int, StockMovement> */
    private function movements(mixed $value): Collection
    {
        return match (true) {
            $value instanceof StockMovement => collect([$value]),
            $value instanceof Collection => $value->filter(fn ($m) => $m instanceof StockMovement)->values(),
            default => collect(),
        };
    }

    /** Baris stok produk di gudang, dibuat bila belum ada, lalu dikunci sampai transaksi selesai. */
    private function lockWarehouseStock(Warehouse $gudang, Product $produk): WarehouseStock
    {
        DB::table('warehouse_stocks')->insertOrIgnore([
            'warehouse_id' => $gudang->getKey(),
            'product_id' => $produk->getKey(),
            'quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WarehouseStock::query()
            ->where('warehouse_id', $gudang->getKey())
            ->where('product_id', $produk->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
