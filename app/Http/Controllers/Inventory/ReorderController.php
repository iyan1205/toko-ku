<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Inventory\ReorderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Inventori → Pesan Ulang: saran pemesanan dari stok min/maks per gudang, jadi draft PO; dan pengaturan min/maks. */
class ReorderController extends Controller
{
    public function __construct(private readonly ReorderService $reorder) {}

    public function index(Request $request): View
    {
        $gudang = Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name', 'is_default', 'branch_id']);
        $dipilih = $request->validate(['gudang' => ['nullable', 'integer']])['gudang'] ?? null;

        return view('inventory.reorder.index', [
            'rows' => $this->reorder->suggestions($dipilih ? [(int) $dipilih] : $gudang->modelKeys()),
            'gudang' => $gudang,
            'dipilih' => $dipilih,
            'suppliers' => Supplier::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.selected' => ['nullable', 'boolean'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'lines.*.warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'lines.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [], ['lines.*.supplier_id' => 'supplier', 'lines.*.quantity' => 'jumlah']);

        $lines = array_values(array_filter($data['lines'], fn ($l) => ! empty($l['selected'])));
        $tanpaSupplier = array_filter($lines, fn ($l) => empty($l['supplier_id']));
        if ($lines === []) {
            return back()->with('error', 'Centang minimal satu barang untuk dibuatkan PO.');
        }
        if ($tanpaSupplier !== []) {
            return back()->withInput()->with('error', 'Pilih supplier untuk setiap barang yang dicentang.');
        }

        $orders = $this->reorder->createDrafts($lines, $request->user());

        return redirect()->route('purchase-orders.index', ['status' => 'draft'])
            ->with('success', $orders->count().' draft PO dibuat: '.$orders->pluck('number')->join(', ').'. Periksa harga lalu ajukan/setujui.');
    }

    public function levels(Request $request): View
    {
        $gudang = Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name', 'is_default']);
        $filter = $request->validate(['gudang' => ['nullable', 'integer'], 'cari' => ['nullable', 'string', 'max:100']]);
        $warehouse = $gudang->firstWhere('id', (int) ($filter['gudang'] ?? 0)) ?? $gudang->firstWhere('is_default', true) ?? $gudang->first();

        $products = Product::aktif()->stockable()->with('unit:id,name')->cari($filter['cari'] ?? null)->orderBy('name')->paginate(30)->withQueryString();
        $levels = WarehouseStock::query()->where('warehouse_id', $warehouse?->id)->whereIn('product_id', $products->getCollection()->modelKeys())->get()->keyBy('product_id');

        return view('inventory.reorder.levels', ['gudang' => $gudang, 'warehouse' => $warehouse, 'products' => $products, 'levels' => $levels, 'filter' => $filter]);
    }

    public function saveLevels(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'levels' => ['required', 'array'],
            'levels.*.min' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'levels.*.max' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [], ['levels.*.min' => 'stok minimum', 'levels.*.max' => 'stok maksimum']);

        foreach ($data['levels'] as $id => $l) {
            if (($l['min'] ?? '') !== '' && ($l['max'] ?? '') !== '' && (float) $l['max'] < (float) $l['min']) {
                return back()->withInput()->with('error', 'Stok maksimum tidak boleh lebih kecil dari stok minimum.');
            }
        }

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $this->reorder->saveLevels($warehouse, $data['levels']);

        return back()->with('success', "Stok minimum/maksimum di {$warehouse->name} tersimpan.");
    }
}
