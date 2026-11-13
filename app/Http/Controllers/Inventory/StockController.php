<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockMovementType;
use App\Exports\Inventory\ExpiringLotsExport;
use App\Exports\Inventory\StockExport;
use App\Exports\Inventory\StockMovementsExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Inventory\ProductionService;
use App\Services\StockService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Posisi stok per gudang, kartu stok, dan penyesuaian manual.
 * Tanpa izin penyesuaian stok, harga beli dan nilai persediaan disembunyikan.
 */
class StockController extends Controller
{
    public function __construct(private readonly StockService $service) {}

    public function index(Request $request): View
    {
        $filter = $this->stockFilter($request);
        $kelola = $request->user()->can('inventory.adjustments.create');
        $list = StockExport::fromRequest($request, $filter)->withCost($kelola);
        $gudang = isset($filter['gudang']) ? Warehouse::withTrashed()->find($filter['gudang']) : null;

        return view('inventory.stock.index', [
            'kelola' => $kelola,
            'list' => $list,
            'produk' => $list->paginate(20),
            'gudangKolom' => $list->warehouses(),
            'gudangDipilih' => $gudang,
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name', 'is_active']),
            'kategori' => Category::orderBy('name')->get(['id', 'name']),
            'ringkasan' => [
                'total' => Product::aktif()->stockable()->count(),
                'menipis' => Product::aktif()->stockable()->stokMenipis()->count(),
                'habis' => Product::aktif()->stockable()->stokHabis()->count(),
                'nilai' => $kelola ? $this->inventoryValue($gudang) : null,
            ],
        ]);
    }

    /** Batch yang sudah/akan kedaluwarsa (Pengaturan → Inventori: jumlah hari). */
    public function expiring(Request $request): View
    {
        $filter = $this->expiringFilter($request);
        $list = ExpiringLotsExport::fromRequest($request, $filter);

        return view('inventory.stock.expiring', [
            'list' => $list,
            'lots' => $list->paginate(25),
            'filter' => $filter,
            'days' => $list->days(),
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name']),
        ]);
    }

    public function exportExpiring(Request $request): Response
    {
        return ExpiringLotsExport::fromRequest($request, $this->expiringFilter($request))->download(ListExport::formatFrom($request));
    }

    /** @return array<string, mixed> */
    private function expiringFilter(Request $request): array
    {
        return $request->validate([
            'hari' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'gudang' => ['nullable', 'integer'],
            'cari' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return StockExport::fromRequest($request, $this->stockFilter($request))
            ->withCost($request->user()->can('inventory.adjustments.create'))
            ->download($format);
    }

    public function movements(Request $request): View
    {
        $filter = $this->movementFilter($request);

        return view('inventory.stock.movements', [
            'mutasi' => StockMovementsExport::fromRequest($request, $filter)->paginate(20),
            'produkDipilih' => isset($filter['produk']) ? Product::withTrashed()->find($filter['produk']) : null,
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name']),
            'jenis' => StockMovementType::cases(),
        ]);
    }

    public function exportMovements(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return StockMovementsExport::fromRequest($request, $this->movementFilter($request))->download($format);
    }

    public function createAdjustment(Request $request): View
    {
        $gudang = Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name', 'is_default']);
        $produk = Product::aktif()->stockable()->with(['unit:id,name', 'recipe.items.product:id,name,unit_id', 'recipe.items.product.unit:id,name'])
            ->orderBy('name')->get(['id', 'sku', 'name', 'stock', 'unit_id', 'track_batches', 'track_serials', 'is_bundle']);

        // Stok tiap produk per gudang aktif, untuk menampilkan "stok saat ini" di formulir.
        $stok = WarehouseStock::query()->whereIn('warehouse_id', $gudang->modelKeys())->where('quantity', '!=', 0)
            ->get(['warehouse_id', 'product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (WarehouseStock $row) => [$row->warehouse_id => (float) $row->quantity]));

        // Batch yang bersisa per produk & gudang (produk berbatch), untuk memilih batch yang dikurangi.
        $lots = StockLot::query()->available()->whereIn('warehouse_id', $gudang->modelKeys())
            ->whereIn('product_id', $produk->where('track_batches', true)->modelKeys())->consumeOrder()->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->groupBy('warehouse_id')->map(fn ($r) => $r->map(fn (StockLot $l) => [
                'id' => $l->id, 'label' => $l->label().' · sisa '.Format::jumlah($l->quantity),
            ])->values()));

        // Produk olahan: bahan per satu satuan produk (resep ÷ hasil), untuk pratinjau produksi.
        $resep = $produk->filter(fn (Product $p) => $p->isProduced())->mapWithKeys(fn (Product $p) => [$p->id => $p->recipe->items
            ->filter(fn ($i) => $i->product)->map(fn ($i) => [
                'name' => $i->product->name, 'unit' => $i->product->unit?->name, 'per' => (float) $i->quantity / $p->recipe->yieldQuantity(),
            ])->values()]);

        return view('inventory.stock.adjust', [
            'resep' => $resep,
            'lots' => $lots,
            'produk' => $produk,
            'gudang' => $gudang,
            'stok' => $stok,
            'dipilih' => $request->integer('produk') ?: null,
            'gudangDipilih' => $request->integer('gudang') ?: $gudang->firstWhere('is_default', true)?->id,
            'jenis' => StockMovementType::manual(),
        ]);
    }

    public function storeAdjustment(StockAdjustmentRequest $request): RedirectResponse
    {
        $produk = Product::findOrFail($request->integer('product_id'));
        $gudang = $request->filled('warehouse_id') ? Warehouse::findOrFail($request->integer('warehouse_id')) : null;

        // Tambah stok produk olahan dengan "ambil dari bahan resep": produksi, bahan berkurang sesuai resep.
        if ($request->input('type') === StockMovementType::Adjustment->value && $request->input('direction') === 'in' && $request->boolean('produksi') && $produk->loadMissing('recipe.items')->isProduced()) {
            $hasil = app(ProductionService::class)->produce($produk, $request->input('quantity'), (string) $request->input('note'), $request->user(), $gudang);
            $masuk = $hasil->last();

            return redirect()->route('stock.movements', ['produk' => $produk->id])->with('success', sprintf(
                'Produksi %s %s dicatat. Stok di %s kini %s; bahan yang dipakai: %s.',
                Format::jumlah($masuk->quantity), $produk->name, $masuk->warehouse->name, Format::jumlah($masuk->stock_after),
                $hasil->slice(0, -1)->map(fn ($m) => Format::jumlah(abs((float) $m->quantity)).' '.$m->product->name)->join(', ') ?: '-',
            ));
        }

        $mutasi = $this->service->sesuaikan(
            $produk,
            StockMovementType::from($request->input('type')),
            (string) $request->input('direction'),
            $request->input('quantity'),
            $request->input('note'),
            $request->user(),
            $gudang,
            StockService::opsiBaris($request->only(['batch_number', 'expiry_date', 'serials']))
                + array_filter(['lot_id' => $request->integer('lot_id') ?: null]),
        );

        return redirect()->route('stock.movements', ['produk' => $produk->id])
            ->with('success', "{$mutasi->type->label()} untuk {$produk->name} dicatat. Stok di {$mutasi->warehouse->name} kini ".Format::jumlah($mutasi->stock_after).'.');
    }

    /** Nilai persediaan (stok positif × harga beli) di satu gudang, atau semua gudang. */
    private function inventoryValue(?Warehouse $gudang): float
    {
        // Nilai dari mesin biaya persediaan (FIFO / rata-rata), per gudang.
        return (float) WarehouseStock::query()
            ->join('products', 'products.id', '=', 'warehouse_stocks.product_id')
            ->whereNull('products.deleted_at')
            ->when($gudang, fn ($q) => $q->where('warehouse_stocks.warehouse_id', $gudang->getKey()))
            ->where('warehouse_stocks.quantity', '>', 0)
            ->sum('warehouse_stocks.value');
    }

    /** @return array<string, mixed> */
    private function stockFilter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'kategori' => ['nullable', 'integer', 'exists:categories,id'],
            'stok' => ['nullable', Rule::in(['aman', 'menipis', 'habis'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function movementFilter(Request $request): array
    {
        return $request->validate([
            'produk' => ['nullable', 'integer', 'exists:products,id'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'jenis' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, StockMovementType::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}
