<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Product;
use App\Models\ProductBreakdown;
use App\Models\ProductBreakdownOutput;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\StockService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pemecahan bahan: N kali pecah memakai N × source_quantity bahan dan menghasilkan N × jumlah tiap hasil
 * sekaligus, misal 2 pack Karkas 1 → 6 dada, 4 paha atas, 4 paha bawah, 4 sayap. Dipecah per kemasan utuh
 * (N bilangan bulat). Biaya bahan yang terpakai dibagi ke hasil (rata per satuan hasil atau sebanding
 * harga jual); nilai persediaan hanya pindah dari bahan ke hasil, tanpa jurnal laba/rugi.
 */
class BreakdownService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array{source_product_id: int, source_quantity: mixed, cost_method: string, notes?: ?string, is_active: bool, outputs: list<array{product_id: int, quantity: mixed}>}  $data
     */
    public function save(?ProductBreakdown $breakdown, array $data): ProductBreakdown
    {
        return DB::transaction(function () use ($breakdown, $data) {
            $breakdown ??= new ProductBreakdown;
            $breakdown->fill(collect($data)->except('outputs')->all())->save();
            $breakdown->outputs()->delete();
            foreach ($data['outputs'] as $o) {
                $breakdown->outputs()->create(['product_id' => (int) $o['product_id'], 'quantity' => $o['quantity']]);
            }

            return $breakdown->load('outputs.product');
        });
    }

    /**
     * Pecah $kali kemasan bahan.
     *
     * @return Collection<int, StockMovement> mutasi bahan (keluar) lalu mutasi tiap hasil (masuk)
     */
    public function run(ProductBreakdown $breakdown, int $kali, string $catatan, User $user, ?Warehouse $gudang = null): Collection
    {
        $breakdown->loadMissing(['source', 'outputs.product']);
        if (! $breakdown->is_active) {
            throw new AturanBisnisException('Pemecahan bahan ini nonaktif.');
        }
        if ($kali < 1) {
            throw new AturanBisnisException('Jumlah yang dipecah minimal 1.');
        }
        if ($breakdown->outputs->isEmpty()) {
            throw new AturanBisnisException('Pemecahan ini belum punya hasil.');
        }

        app(FiscalPeriodService::class)->ensureOpen(now());
        $gudang ??= $this->stock->defaultWarehouse();
        $bahanMilli = (int) round($kali * (float) $breakdown->source_quantity * 1000);
        $keterangan = trim("Pecah {$kali} × ".Format::jumlah($breakdown->source_quantity)." {$breakdown->source->unit?->name} {$breakdown->source->name}".($catatan !== '' ? ": {$catatan}" : ''));

        return DB::transaction(function () use ($breakdown, $kali, $bahanMilli, $keterangan, $user, $gudang) {
            $keluar = $this->stock->catat($breakdown->source, StockMovementType::Production, '-'.Uang::milliKeDesimal($bahanMilli), null, $keterangan, $user, $gudang);
            $mutasi = collect([$keluar]);

            $hasil = $breakdown->outputs->map(fn (ProductBreakdownOutput $o) => [
                'product' => $o->product, 'milli' => (int) round($kali * (float) $o->quantity * 1000),
            ])->filter(fn ($h) => $h['product'] && $h['milli'] > 0)->values();
            $biaya = $this->allocate(abs(Uang::sen($keluar->total_cost)), $hasil, $breakdown->cost_method);

            foreach ($hasil as $i => $h) {
                $mutasi->push($this->stock->catat($h['product'], StockMovementType::Production, Uang::milliKeDesimal($h['milli']), null, $keterangan, $user, $gudang,
                    ['unit_cost' => Uang::perUnit($biaya[$i], $h['milli'])]));
            }

            activity('inventori')->performedOn($breakdown)->causedBy($user)->event('produced')
                ->withProperties(['kali' => $kali, 'gudang' => $gudang->name, 'biaya' => Uang::keDesimal(abs(Uang::sen($keluar->total_cost)))])
                ->log($keterangan);

            return $mutasi;
        });
    }

    /**
     * Pintasan dari Penyesuaian Stok: menambah $jumlah produk hasil (misal 3 dada) = memecah bahan sebanyak
     * jumlah ÷ hasil per kemasan (1 pack), dan semua hasil lain ikut bertambah.
     *
     * @return Collection<int, StockMovement>
     */
    public function runForOutput(Product $produk, float|int|string $jumlah, string $catatan, User $user, ?Warehouse $gudang = null): Collection
    {
        $breakdown = $produk->breakdownSource() ?? throw new AturanBisnisException("{$produk->name} bukan hasil pemecahan bahan.");

        return $this->run($breakdown, $this->countFor($breakdown, $produk, $jumlah), $catatan, $user, $gudang);
    }

    /** Berapa kali pecah untuk mendapat $jumlah produk hasil ini; harus kemasan utuh. */
    public function countFor(ProductBreakdown $breakdown, Product $produk, float|int|string $jumlah): int
    {
        $per = (float) $breakdown->outputs->firstWhere('product_id', $produk->id)?->quantity;
        $kali = $per > 0 ? (float) $jumlah / $per : 0;
        if ($kali < 1 || abs($kali - round($kali)) > 0.0001) {
            throw new AturanBisnisException(sprintf(
                'Jumlah %s harus kelipatan %s, karena 1 kali pecah %s %s %s menghasilkan %s %s.',
                $produk->name, Format::jumlah($per), Format::jumlah($breakdown->source_quantity), $breakdown->source->unit?->name, $breakdown->source->name,
                Format::jumlah($per), $produk->unit?->name,
            ));
        }

        return (int) round($kali);
    }

    /**
     * Bagi biaya (sen) ke hasil: rata per satuan hasil, atau sebanding harga jual × jumlah. Sisa pembulatan
     * masuk ke hasil terakhir supaya total biaya hasil = biaya bahan.
     *
     * @param  Collection<int, array{product: Product, milli: int}>  $hasil
     * @return list<int>
     */
    private function allocate(int $total, Collection $hasil, string $metode): array
    {
        $bobot = $hasil->map(fn ($h) => $metode === 'price' ? Uang::kali($h['milli'], Uang::sen($h['product']->selling_price)) : $h['milli'])->all();
        if (array_sum($bobot) <= 0) {
            $bobot = $hasil->map(fn ($h) => $h['milli'])->all();
        }
        $jumlahBobot = array_sum($bobot);

        $hasilBiaya = [];
        $terpakai = 0;
        foreach ($bobot as $i => $b) {
            $bagian = $i === count($bobot) - 1 ? $total - $terpakai : Uang::kaliBagi($total, $b, $jumlahBobot);
            $hasilBiaya[] = $bagian;
            $terpakai += $bagian;
        }

        return $hasilBiaya;
    }
}
