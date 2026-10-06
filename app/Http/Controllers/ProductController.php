<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportGagalException;
use App\Exports\ProductImportTemplate;
use App\Exports\ProductsExport;
use App\Http\Requests\ImportProductRequest;
use App\Http\Requests\ProductRequest;
use App\Imports\ProductsImport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\ProductImportService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
        private readonly ProductImportService $importService,
    ) {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $produk = Product::query()
            ->with(['category', 'unit'])
            ->filter($this->filter($request))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('products.index', [
            'produk' => $produk,
            'kategori' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('products.form', $this->dataForm(new Product(['is_active' => true])));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $produk = $this->service->buat(
            $request->safe()->except(['photo', 'remove_photo']),
            $request->file('photo'),
        );

        return redirect()->route('products.show', $produk)
            ->with('success', "Produk {$produk->name} berhasil ditambahkan dengan SKU {$produk->sku}.");
    }

    public function show(Product $product): View
    {
        Gate::authorize('view', $product);

        return view('products.show', [
            'produk' => $product->load(['category', 'unit']),
            'riwayat' => auth()->user()->can('manage-stock')
                ? $product->stockMovements()->with('user')->orderByDesc('id')->limit(5)->get()
                : collect(),
        ]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.form', $this->dataForm($product));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->service->perbarui(
            $product,
            $request->safe()->except(['photo', 'remove_photo']),
            $request->file('photo'),
            $request->boolean('remove_photo'),
        );

        return redirect()->route('products.show', $product)
            ->with('success', "Produk {$product->name} berhasil diperbarui.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $this->service->hapus($product);

        return redirect()->route('products.index')
            ->with('success', "Produk {$product->name} dihapus. Riwayat transaksinya tetap tersimpan.");
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('export', Product::class);

        return Excel::download(new ProductsExport($this->filter($request)), 'produk-' . now()->format('Ymd') . '.xlsx');
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('import', Product::class);

        return Excel::download(new ProductImportTemplate(), 'template-impor-produk.xlsx');
    }

    public function importForm(): View
    {
        Gate::authorize('import', Product::class);

        return view('products.import');
    }

    public function import(ImportProductRequest $request): RedirectResponse
    {
        try {
            $pembaca = new ProductsImport();
            Excel::import($pembaca, $request->file('file'));

            $hasil = $this->importService->impor($pembaca->rows);
        } catch (ImportGagalException $e) {
            return back()->with('error', $e->getMessage())->with('import_errors', $e->daftar());
        }

        return redirect()->route('products.index')->with(
            'success',
            "Impor selesai: {$hasil['dibuat']} produk ditambahkan, {$hasil['diperbarui']} diperbarui."
        );
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'integer', 'exists:categories,id'],
            'stok' => ['nullable', Rule::in(['aman', 'menipis', 'habis'])],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function dataForm(Product $produk): array
    {
        return [
            'produk' => $produk,
            'kategori' => Category::orderBy('name')->get(['id', 'name']),
            'satuan' => Unit::orderBy('name')->get(['id', 'name']),
        ];
    }
}
