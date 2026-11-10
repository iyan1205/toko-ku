<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Settings\DocumentNumberService;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stok opname per gudang, dua langkah:
 *   - Draft: hitungan fisik disimpan beserta stok sistem saat dihitung. Stok belum berubah.
 *   - Selesai (final): selisih dibukukan sebagai mutasi penyesuaian di gudang itu.
 *
 * Selisih selalu dihitung terhadap stok sistem saat produk dihitung. Bila stok gudang bergeser
 * sebelum opname diselesaikan (ada penjualan/penerimaan), hitungan produk itu tidak lagi sebanding,
 * sehingga ditolak dan harus dihitung ulang, bukan ditebak.
 */
class StockOpnameService
{
    public function __construct(
        private readonly StockService $stok,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @param  array{warehouse_id?:?int, opname_date:string, note?:?string, items:array<int, array{system_stock?:mixed, physical_stock?:mixed, reason?:?string}>}  $data
     *                                                                                                                                                                    items diindeks dengan ID produk. Baris tanpa stok fisik dianggap tidak dihitung dan dilewati.
     */
    public function create(array $data, User $user, bool $finalize): StockOpname
    {
        $gudang = isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : $this->stok->defaultWarehouse();

        // Satu transaksi: bila "simpan & selesaikan" gagal, draft juga tidak tersimpan.
        return DB::transaction(function () use ($data, $user, $gudang, $finalize) {
            $opname = StockOpname::create([
                'number' => $this->numbers->next(DocumentType::StockOpname, $data['opname_date']),
                'warehouse_id' => $gudang->getKey(),
                'opname_date' => $data['opname_date'],
                'note' => $data['note'] ?? null,
                'user_id' => $user->getKey(),
            ]);
            $opname->forceFill(['status' => StockOpnameStatus::Draft])->save();

            $this->writeItems($opname, $gudang, $data['items'] ?? []);

            return $finalize ? $this->finalize($opname, $user, fromForm: true) : $opname->load('items.product');
        });
    }

    /** Ganti tanggal, catatan, dan seluruh hitungan draft. Gudang tidak bisa diganti. */
    public function updateDraft(StockOpname $opname, array $data, User $user, bool $finalize): StockOpname
    {
        return DB::transaction(function () use ($opname, $data, $user, $finalize) {
            $terkunci = $this->lockDraft($opname);

            $terkunci->fill(['opname_date' => $data['opname_date'], 'note' => $data['note'] ?? null])->save();
            $terkunci->items()->delete();
            $this->writeItems($terkunci, $terkunci->warehouse, $data['items'] ?? []);

            return $finalize ? $this->finalize($terkunci, $user, fromForm: true) : $terkunci->load('items.product');
        });
    }

    /**
     * Bukukan selisih draft ke stok gudangnya.
     *
     * @param  bool  $fromForm  dipanggil dari formulir: kesalahan alasan ditampilkan per baris
     */
    public function finalize(StockOpname $opname, User $user, bool $fromForm = false): StockOpname
    {
        return DB::transaction(function () use ($opname, $user, $fromForm) {
            $terkunci = $this->lockDraft($opname);
            $gudang = $terkunci->warehouse;
            $items = $terkunci->items()->orderBy('product_id')->get();

            // Urutan kunci sama dengan StockService (produk, lalu stok gudang) agar tidak deadlock.
            $produk = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $stokKini = $this->warehouseQuantities($gudang, $items->pluck('product_id'), lock: true);

            $berubah = [];
            $tanpaAlasan = [];
            foreach ($items as $item) {
                $kini = $stokKini[$item->product_id] ?? 0.0;
                $nama = $produk[$item->product_id]->name;

                if (abs($kini - (float) $item->system_stock) > 0.0005) {
                    $berubah[] = sprintf('%s (saat dihitung %s, kini %s)', $nama, Format::jumlah($item->system_stock), Format::jumlah($kini));
                } elseif ((float) $item->difference != 0.0 && blank($item->reason)) {
                    $tanpaAlasan["items.{$item->product_id}.reason"] = "Alasan selisih wajib diisi untuk {$nama}.";
                }
            }

            if ($berubah) {
                throw new AturanBisnisException(
                    'Stok berubah sejak dihitung: '.implode(', ', $berubah).'. Ubah draft lalu hitung ulang produk tersebut.'
                );
            }

            if ($tanpaAlasan) {
                if ($fromForm) {
                    throw ValidationException::withMessages($tanpaAlasan);
                }

                throw new AturanBisnisException('Alasan selisih belum diisi untuk '.count($tanpaAlasan).' produk. Ubah draft untuk melengkapinya.');
            }

            foreach ($items as $item) {
                if ((float) $item->difference != 0.0) {
                    $this->stok->catat(
                        $produk[$item->product_id],
                        StockMovementType::Adjustment,
                        $item->difference,
                        $terkunci,
                        "Stok opname {$terkunci->number}: {$item->reason}",
                        $user,
                        $gudang,
                    );
                }
            }

            $terkunci->forceFill([
                'status' => StockOpnameStatus::Final,
                'finalized_at' => now(),
                'finalized_by' => $user->getKey(),
            ])->save();

            activity('inventori')->performedOn($terkunci)->causedBy($user)->event('approved')
                ->withProperties(['gudang' => $gudang->name, 'produk' => $items->count(), 'berselisih' => $items->where('difference', '!=', 0)->count()])
                ->log("Stok opname {$terkunci->number} diselesaikan");

            return $terkunci->load('items.product');
        });
    }

    /** Hapus draft yang tidak jadi dipakai. Opname yang sudah selesai tidak bisa dihapus. */
    public function deleteDraft(StockOpname $opname): void
    {
        DB::transaction(function () use ($opname) {
            $terkunci = $this->lockDraft($opname);
            $terkunci->items()->delete();
            $terkunci->delete();
        });
    }

    /**
     * Stok produk-produk di satu gudang (produk tanpa baris = 0).
     *
     * @param  Collection<int, int>|array<int, int>  $productIds
     * @return array<int, float>
     */
    public function warehouseQuantities(Warehouse $gudang, Collection|array $productIds, bool $lock = false): array
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $gudang->getKey())
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['product_id', 'quantity'])
            ->mapWithKeys(fn (WarehouseStock $row) => [$row->product_id => round((float) $row->quantity, 3)])
            ->all();
    }

    /** @param array<int, array{system_stock?:mixed, physical_stock?:mixed, reason?:?string}> $items */
    private function writeItems(StockOpname $opname, Warehouse $gudang, array $items): void
    {
        $dihitung = collect($items)->filter(fn ($i) => isset($i['physical_stock']) && $i['physical_stock'] !== '');

        if ($dihitung->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Isi stok fisik minimal untuk satu produk.']);
        }

        $produk = Product::whereIn('id', $dihitung->keys())->get()->keyBy('id');
        if ($dihitung->keys()->diff($produk->keys())->isNotEmpty()) {
            throw new AturanBisnisException('Ada produk yang tidak ditemukan atau sudah dihapus. Muat ulang halaman.');
        }

        $stokKini = $this->warehouseQuantities($gudang, $dihitung->keys());

        // Stok yang dilihat penghitung harus sama dengan stok gudang saat disimpan.
        $berubah = $dihitung->filter(fn ($item, $id) => abs(($stokKini[$id] ?? 0.0) - round((float) ($item['system_stock'] ?? 0), 3)) > 0.0005)
            ->map(fn ($item, $id) => sprintf('%s (kini %s)', $produk[$id]->name, Format::jumlah($stokKini[$id] ?? 0)));

        if ($berubah->isNotEmpty()) {
            throw new AturanBisnisException(
                'Stok berubah sejak halaman dibuka: '.$berubah->implode(', ').'. Muat ulang halaman lalu hitung ulang.'
            );
        }

        foreach ($dihitung->sortKeys() as $id => $item) {
            $sistem = $stokKini[$id] ?? 0.0;
            $fisik = round((float) $item['physical_stock'], 3);

            $opname->items()->create([
                'product_id' => $id,
                'system_stock' => $sistem,
                'physical_stock' => $fisik,
                'difference' => round($fisik - $sistem, 3),
                'reason' => trim((string) ($item['reason'] ?? '')) ?: null,
            ]);
        }
    }

    private function lockDraft(StockOpname $opname): StockOpname
    {
        $terkunci = StockOpname::whereKey($opname->getKey())->lockForUpdate()->firstOrFail();

        if (! $terkunci->isDraft()) {
            throw new AturanBisnisException("Stok opname {$terkunci->number} sudah selesai dan tidak bisa diubah.");
        }

        return $terkunci;
    }
}
