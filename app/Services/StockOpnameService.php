<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Events\StockOpnameFinalized;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\Scopes\BranchScope;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Approval\ApprovalService;
use App\Services\Settings\DocumentNumberService;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stok opname per gudang:
 *   - Draft: hitungan fisik disimpan beserta stok sistem saat dihitung (lewat formulir, atau scan barcode
 *     satu per satu dari HP). Stok belum berubah. Opsional: stok gudang dibekukan selama dihitung
 *     (StockService menolak mutasi lain di gudang itu).
 *   - Menunggu persetujuan: bila ada alur "Stok opname" yang cocok (Pengaturan → Alur Persetujuan).
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
        private readonly ApprovalService $approvals,
    ) {}

    /** Mulai opname kosong (dihitung lewat scan). $freeze = bekukan stok gudang sampai opname selesai. */
    public function start(Warehouse $gudang, string $tanggal, ?string $catatan, bool $freeze, User $user): StockOpname
    {
        return DB::transaction(function () use ($gudang, $tanggal, $catatan, $freeze, $user) {
            if ($freeze && ($lain = $this->frozenOpname($gudang)) !== null) {
                throw new AturanBisnisException("Gudang {$gudang->name} sudah dibekukan oleh opname {$lain->number}.");
            }
            $opname = StockOpname::create([
                'number' => $this->numbers->next(DocumentType::StockOpname, $tanggal),
                'warehouse_id' => $gudang->getKey(),
                'opname_date' => $tanggal,
                'note' => $catatan,
                'user_id' => $user->getKey(),
            ]);
            $opname->forceFill(['status' => StockOpnameStatus::Draft, 'freeze_stock' => $freeze])->save();

            return $opname;
        });
    }

    /**
     * Satu hasil hitung (scan): $mode add = tambahkan ke hitungan, set = ganti hitungan. Stok sistem diambil saat
     * produk pertama kali dihitung.
     */
    public function scan(StockOpname $opname, Product $produk, float $jumlah, string $mode, User $user): StockOpnameItem
    {
        return DB::transaction(function () use ($opname, $produk, $jumlah, $mode) {
            $terkunci = $this->lockDraft($opname);
            if ($produk->isVariantParent() || $produk->is_bundle) {
                throw new AturanBisnisException("{$produk->name} tidak punya stok sendiri; hitung varian/komponennya.");
            }
            $item = $terkunci->items()->where('product_id', $produk->id)->first();
            $sistem = $item ? (float) $item->system_stock : ($this->warehouseQuantities($terkunci->warehouse, [$produk->id])[$produk->id] ?? 0.0);
            $fisik = round($mode === 'set' ? $jumlah : (float) ($item?->physical_stock ?? 0) + $jumlah, 3);
            if ($fisik < 0) {
                throw new AturanBisnisException('Hitungan fisik tidak boleh minus.');
            }

            $item ??= $terkunci->items()->make(['product_id' => $produk->id, 'system_stock' => $sistem]);
            $item->fill(['physical_stock' => $fisik, 'difference' => round($fisik - $sistem, 3)])->save();

            return $item;
        });
    }

    /**
     * Selesaikan, atau ajukan dulu bila ada alur persetujuan yang cocok.
     *
     * @return array{0: StockOpname, 1: bool} [opname, sudah diajukan untuk persetujuan]
     */
    public function finalizeOrSubmit(StockOpname $opname, User $user, bool $fromForm = false): array
    {
        if ($this->approvals->requiresApproval($opname->fresh())) {
            $this->checkReady($opname->fresh(), $fromForm);
            $this->approvals->submit($opname->fresh(), $user);

            return [$opname->fresh(), true];
        }

        return [$this->finalize($opname, $user, $fromForm), false];
    }

    /** Opname lain yang sedang membekukan gudang ini, atau null. */
    public function frozenOpname(Warehouse $gudang, ?int $kecuali = null): ?StockOpname
    {
        return StockOpname::query()->withoutGlobalScope(BranchScope::class)->where('warehouse_id', $gudang->getKey())
            ->whereIn('status', [StockOpnameStatus::Draft->value, StockOpnameStatus::Submitted->value])
            ->where('freeze_stock', true)->when($kecuali, fn ($q, $id) => $q->whereKeyNot($id))->first();
    }

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

            return $finalize ? $this->finalizeOrSubmit($opname, $user, fromForm: true)[0] : $opname->load('items.product');
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

            return $finalize ? $this->finalizeOrSubmit($terkunci, $user, fromForm: true)[0] : $terkunci->load('items.product');
        });
    }

    /**
     * Bukukan selisih draft ke stok gudangnya.
     *
     * @param  bool  $fromForm  dipanggil dari formulir: kesalahan alasan ditampilkan per baris
     */
    public function finalize(StockOpname $opname, User $user, bool $fromForm = false, bool $approved = false): StockOpname
    {
        return DB::transaction(function () use ($opname, $user, $fromForm, $approved) {
            $terkunci = $this->lockDraft($opname, allowSubmitted: $approved);
            if (! $approved && $this->approvals->requiresApproval($terkunci)) {
                throw new AturanBisnisException("Stok opname {$terkunci->number} perlu persetujuan. Ajukan lewat tombol Ajukan persetujuan.");
            }
            app(FiscalPeriodService::class)->ensureOpen($terkunci->opname_date);
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

            StockOpnameFinalized::dispatch($terkunci);

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

    /** Hitungan lengkap: tidak ada stok yang bergeser sejak dihitung dan semua selisih beralasan. */
    private function checkReady(StockOpname $opname, bool $fromForm): void
    {
        $items = $opname->items()->get();
        if ($items->isEmpty()) {
            throw new AturanBisnisException('Belum ada produk yang dihitung.');
        }
        $tanpaAlasan = $items->filter(fn ($i) => (float) $i->difference != 0.0 && blank($i->reason));
        if ($tanpaAlasan->isNotEmpty()) {
            if ($fromForm) {
                throw ValidationException::withMessages($tanpaAlasan->mapWithKeys(fn ($i) => ["items.{$i->product_id}.reason" => 'Alasan selisih wajib diisi.'])->all());
            }
            throw new AturanBisnisException('Alasan selisih belum diisi untuk '.$tanpaAlasan->count().' produk.');
        }
    }

    private function lockDraft(StockOpname $opname, bool $allowSubmitted = false): StockOpname
    {
        $terkunci = StockOpname::whereKey($opname->getKey())->lockForUpdate()->firstOrFail();

        if ($allowSubmitted && $terkunci->status === StockOpnameStatus::Submitted) {
            return $terkunci;
        }

        if (! $terkunci->isDraft()) {
            throw new AturanBisnisException("Stok opname {$terkunci->number} sudah {$terkunci->status->label()} dan tidak bisa diubah.");
        }

        return $terkunci;
    }
}
