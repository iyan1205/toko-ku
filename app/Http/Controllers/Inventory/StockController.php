<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockMovementType;
use App\Exports\Inventory\StockExport;
use App\Exports\Inventory\StockMovementsExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
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
                'total' => Product::aktif()->count(),
                'menipis' => Product::aktif()->stokMenipis()->count(),
                'habis' => Product::aktif()->stokHabis()->count(),
                'nilai' => $kelola ? $this->inventoryValue($gudang) : null,
            ],
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
        $produk = Product::aktif()->with('unit:id,name')->orderBy('name')->get(['id', 'sku', 'name', 'stock', 'unit_id']);

        // Stok tiap produk per gudang aktif, untuk menampilkan "stok saat ini" di formulir.
        $stok = WarehouseStock::query()->whereIn('warehouse_id', $gudang->modelKeys())->where('quantity', '!=', 0)
            ->get(['warehouse_id', 'product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (WarehouseStock $row) => [$row->warehouse_id => (float) $row->quantity]));

        return view('inventory.stock.adjust', [
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

        $mutasi = $this->service->sesuaikan(
            $produk,
            StockMovementType::from($request->input('type')),
            (string) $request->input('direction'),
            $request->input('quantity'),
            $request->input('note'),
            $request->user(),
            $gudang,
        );

        return redirect()->route('stock.movements', ['produk' => $produk->id])
            ->with('success', "{$mutasi->type->label()} untuk {$produk->name} dicatat. Stok di {$mutasi->warehouse->name} kini ".Format::jumlah($mutasi->stock_after).'.');
    }

    /** Nilai persediaan (stok positif × harga beli) di satu gudang, atau semua gudang. */
    private function inventoryValue(?Warehouse $gudang): float
    {
        if ($gudang === null) {
            return (float) Product::query()->where('stock', '>', 0)->selectRaw('COALESCE(SUM(stock * purchase_price), 0) as nilai')->value('nilai');
        }

        return (float) WarehouseStock::query()
            ->join('products', 'products.id', '=', 'warehouse_stocks.product_id')
            ->where('warehouse_stocks.warehouse_id', $gudang->getKey())
            ->where('warehouse_stocks.quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(warehouse_stocks.quantity * products.purchase_price), 0) as nilai')
            ->value('nilai');
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
