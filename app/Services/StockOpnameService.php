<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockOpnameService
{
    public function __construct(
        private readonly StockService $stok,
        private readonly KodeService $kode,
    ) {
    }

    /**
     * @param array{opname_date:string, note?:?string, items:array<int, array{system_stock?:mixed, physical_stock?:mixed, reason?:?string}>} $data
     *        items diindeks dengan ID produk. Baris tanpa stok fisik dianggap tidak dihitung dan dilewati.
     */
    public function simpan(array $data, User $user): StockOpname
    {
        $dihitung = collect($data['items'] ?? [])->filter(
            fn ($i) => isset($i['physical_stock']) && $i['physical_stock'] !== ''
        );

        if ($dihitung->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Isi stok fisik minimal untuk satu produk.']);
        }

        return DB::transaction(function () use ($data, $dihitung, $user) {
            // Urut by id agar dua opname bersamaan mengunci baris dengan urutan sama (cegah deadlock).
            $produk = Product::whereIn('id', $dihitung->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($dihitung->keys()->diff($produk->keys())->isNotEmpty()) {
                throw new AturanBisnisException('Ada produk yang tidak ditemukan atau sudah dihapus. Muat ulang halaman.');
            }

            $berubah = [];
            $tanpaAlasan = [];

            foreach ($dihitung as $id => $item) {
                $p = $produk[$id];
                $sistem = round((float) $p->stock, 3);
                $dilihat = round((float) ($item['system_stock'] ?? 0), 3);
                $fisik = round((float) $item['physical_stock'], 3);

                if (abs($sistem - $dilihat) > 0.0005) {
                    $berubah[] = sprintf('%s (kini %s)', $p->name, Format::jumlah($sistem));
                } elseif (abs($fisik - $sistem) > 0.0005 && trim((string) ($item['reason'] ?? '')) === '') {
                    $tanpaAlasan["items.{$id}.reason"] = "Alasan selisih wajib diisi untuk {$p->name}.";
                }
            }

            // Selisih dihitung terhadap stok yang dilihat penghitung. Bila stok bergeser (ada penjualan
            // saat menghitung), hitungan fisik tidak lagi sebanding, jadi ditolak, bukan ditebak.
            if ($berubah) {
                throw new AturanBisnisException(
                    'Stok berubah sejak halaman dibuka: ' . implode(', ', $berubah) . '. Muat ulang halaman lalu hitung ulang.'
                );
            }

            if ($tanpaAlasan) {
                throw ValidationException::withMessages($tanpaAlasan);
            }

            $tanggal = $data['opname_date'];
            $opname = StockOpname::create([
                'number' => $this->kode->berikutnya(StockOpname::class, 'number', 'OPN-' . date('Ymd', strtotime($tanggal)) . '-', 4),
                'opname_date' => $tanggal,
                'note' => $data['note'] ?? null,
                'user_id' => $user->getKey(),
            ]);

            foreach ($dihitung as $id => $item) {
                $p = $produk[$id];
                $sistem = round((float) $p->stock, 3);
                $fisik = round((float) $item['physical_stock'], 3);
                $selisih = round($fisik - $sistem, 3);
                $alasan = trim((string) ($item['reason'] ?? '')) ?: null;

                $opname->items()->create([
                    'product_id' => $p->getKey(),
                    'system_stock' => $sistem,
                    'physical_stock' => $fisik,
                    'difference' => $selisih,
                    'reason' => $alasan,
                ]);

                // Hanya selisih yang mengubah stok; produk yang cocok tidak menghasilkan mutasi.
                if ($selisih != 0.0) {
                    $this->stok->catat(
                        $p,
                        StockMovementType::Adjustment,
                        $selisih,
                        $opname,
                        "Stok opname {$opname->number}: {$alasan}",
                        $user,
                    );
                }
            }

            return $opname->load('items.product');
        });
    }
}
