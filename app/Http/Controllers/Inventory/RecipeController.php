<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\Inventory\RecipesExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecipeRequest;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Unit;
use App\Services\RecipeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bill of Materials (BOM) — resep per produk jual.
 * Harga bahan baku dibaca langsung dari produk (purchase_price), jadi perubahan harga
 * otomatis tercermin. Tidak ada duplikasi harga di sini.
 */
class RecipeController extends Controller
{
    public function __construct(private readonly RecipeService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('inventory.recipes.view');
        $list = RecipesExport::fromRequest($request, $this->filter($request));

        return view('inventory.recipes.index', [
            'list' => $list,
            'recipes' => $list->paginate(15),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return RecipesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('inventory.recipes.form', [
            'recipe' => null,
            'produkList' => Product::jual()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'selling_price']),
            'bahanList' => Product::bahanBaku()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'purchase_price']),
            'units' => Unit::orderBy('name')->get(),
        ]);
    }

    public function store(RecipeRequest $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $data = $request->validated();
        $product = Product::findOrFail($data['product_id']);

        $items = [];
        foreach ($data['items'] ?? [] as $it) {
            $items[] = [
                'product_id' => (int) ($it['product_id'] ?? 0),
                'quantity' => (float) ($it['quantity'] ?? 0),
                'notes' => $it['notes'] ?? '',
            ];
        }

        $recipe = $this->service->simpan($product, $items, $data['notes'] ?? '');

        return redirect()->route('recipes.show', $recipe)
            ->with('success', "BOM untuk {$product->name} tersimpan.");
    }

    public function show(Recipe $recipe): View
    {
        Gate::authorize('inventory.recipes.view');

        return view('inventory.recipes.show', [
            'recipe' => $recipe->load('items.product.unit'),
            'totalBiaya' => $this->service->hitungBiaya($recipe),
        ]);
    }

    public function edit(Recipe $recipe): View
    {
        Gate::authorize('update', $recipe->product);

        return view('inventory.recipes.form', [
            'recipe' => $recipe->load('items.product.unit'),
            'produkList' => Product::jual()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'selling_price']),
            'bahanList' => Product::bahanBaku()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'purchase_price']),
            'units' => Unit::orderBy('name')->get(),
        ]);
    }

    public function update(RecipeRequest $request, Recipe $recipe): RedirectResponse
    {
        Gate::authorize('update', $recipe->product);

        $data = $request->validated();
        $product = Product::findOrFail($data['product_id']);

        $items = [];
        foreach ($data['items'] ?? [] as $it) {
            $items[] = [
                'product_id' => (int) ($it['product_id'] ?? 0),
                'quantity' => (float) ($it['quantity'] ?? 0),
                'notes' => $it['notes'] ?? '',
            ];
        }

        $recipe = $this->service->simpan($product, $items, $data['notes'] ?? '');

        return redirect()->route('recipes.show', ['recipe' => $recipe])
            ->with('success', "BOM untuk {$product->name} diperbarui.");
    }

    public function destroy(Recipe $recipe): RedirectResponse
    {
        Gate::authorize('delete', $recipe->product);

        $nama = $recipe->product?->name ?? 'produk';
        $recipe->delete();

        return redirect()->route('recipes.index')
            ->with('success', "BOM untuk {$nama} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate(['cari' => ['nullable', 'string', 'max:100']]);
    }
}
