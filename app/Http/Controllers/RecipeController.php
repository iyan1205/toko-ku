<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecipeRequest;
use App\Models\Product;
use App\Services\RecipeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Bill of Materials (BOM) — resep per produk jual.
 * Harga bahan baku dibaca langsung dari produk (purchase_price), jadi perubahan harga
 * otomatis tercermin. Tidak ada duplikasi harga di sini.
 */
class RecipeController extends Controller
{
    public function __construct(private readonly RecipeService $service)
    {
    }

    public function index(): View
    {
        Gate::authorize('view-recipes');

        return view('recipes.index', [
            'recipes' => $this->service->semua(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('recipes.form', [
            'recipe' => null,
            'produkList' => Product::jual()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'selling_price']),
            'bahanList' => Product::bahanBaku()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'purchase_price']),
            'units' => \App\Models\Unit::orderBy('name')->get(),
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

    public function show(\App\Models\Recipe $recipe): View
    {
        Gate::authorize('view-recipes');

        return view('recipes.show', [
            'recipe' => $recipe->load('items.product.unit'),
            'totalBiaya' => $this->service->hitungBiaya($recipe),
        ]);
    }

    public function edit(\App\Models\Recipe $recipe): View
    {
        Gate::authorize('update', $recipe->product);

        return view('recipes.form', [
            'recipe' => $recipe->load('items.product.unit'),
            'produkList' => Product::jual()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'selling_price']),
            'bahanList' => Product::bahanBaku()->orderBy('name')->get(['id', 'name', 'sku', 'unit_id', 'purchase_price']),
            'units' => \App\Models\Unit::orderBy('name')->get(),
        ]);
    }

    public function update(RecipeRequest $request, \App\Models\Recipe $recipe): RedirectResponse
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

    public function destroy(\App\Models\Recipe $recipe): RedirectResponse
    {
        Gate::authorize('delete', $recipe->product);

        $nama = $recipe->product?->name ?? 'produk';
        $recipe->delete();

        return redirect()->route('recipes.index')
            ->with('success', "BOM untuk {$nama} dihapus.");
    }
}
