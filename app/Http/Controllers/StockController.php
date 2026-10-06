<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockController extends Controller
{
    public function __construct(private readonly StockService $service)
    {
    }

    /** Ringkasan stok semua produk. Kasir (view-stock) melihat stok tanpa nilai persediaan. */
    public function index(Request $request): View
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'integer', 'exists:categories,id'],
            'stok' => ['nullable', Rule::in(['aman', 'menipis', 'habis'])],
        ]);

        $kelola = $request->user()->can('manage-stock');

        return view('stock.index', [
            'kelola' => $kelola,
            'produk' => Product::query()->with(['category', 'unit'])->filter($filter)->orderBy('name')->paginate(20)->withQueryString(),
            'kategori' => Category::orderBy('name')->get(['id', 'name']),
            'ringkasan' => [
                'total' => Product::aktif()->count(),
                'menipis' => Product::aktif()->stokMenipis()->count(),
                'habis' => Product::aktif()->stokHabis()->count(),
                'nilai' => $kelola
                    ? (float) Product::query()->where('stock', '>', 0)->selectRaw('COALESCE(SUM(stock * purchase_price), 0) as nilai')->value('nilai')
                    : null,
            ],
        ]);
    }

    public function movements(Request $request): View
    {
        $filter = $request->validate([
            'produk' => ['nullable', 'integer', 'exists:products,id'],
            'jenis' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, StockMovementType::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        return view('stock.movements', [
            'mutasi' => StockMovement::query()
                ->with(['product', 'user', 'reference'])
                ->filter($filter)
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'produkDipilih' => isset($filter['produk']) ? Product::withTrashed()->find($filter['produk']) : null,
            'jenis' => StockMovementType::cases(),
        ]);
    }

    public function createAdjustment(Request $request): View
    {
        return view('stock.adjust', [
            'produk' => Product::aktif()->with('unit:id,name')->orderBy('name')->get(['id', 'sku', 'name', 'stock', 'unit_id']),
            'dipilih' => $request->integer('produk') ?: null,
            'jenis' => StockMovementType::manual(),
        ]);
    }

    public function storeAdjustment(StockAdjustmentRequest $request): RedirectResponse
    {
        $produk = Product::findOrFail($request->integer('product_id'));

        $mutasi = $this->service->sesuaikan(
            $produk,
            StockMovementType::from($request->input('type')),
            (string) $request->input('direction'),
            $request->input('quantity'),
            $request->input('note'),
            $request->user(),
        );

        return redirect()->route('stock.movements', ['produk' => $produk->id])
            ->with('success', "{$mutasi->type->label()} untuk {$produk->name} dicatat. Stok kini " . \App\Support\Format::jumlah($mutasi->stock_after) . '.');
    }
}
