<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;

use App\Exceptions\ImportGagalException;
use App\Exports\ProductImportTemplate;
use App\Exports\ListExport;
use App\Exports\ProductsExport;
use App\Http\Requests\ImportProductRequest;
use App\Http\Requests\ProductRequest;
use App\Imports\ProductsImport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\ProductImportService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
        private readonly ProductImportService $importService,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $produk = ProductsExport::fromRequest($request, $this->filter($request))->paginate(15);

        return view('inventory.products.index', [
            'produk' => $produk,
            'kategori' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('inventory.products.form', $this->dataForm(new Product(['is_active' => true])));
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

        return view('inventory.products.show', [
            'produk' => $product->load(['category', 'unit']),
            // Stok per gudang: semua gudang aktif, ditambah gudang nonaktif yang masih menyimpan produk ini.
            'stokGudang' => Warehouse::withTrashed()->ordered()->get()
                ->map(fn (Warehouse $w) => ['gudang' => $w, 'jumlah' => $product->stockIn($w)])
                ->filter(fn (array $row) => ($row['gudang']->is_active && ! $row['gudang']->trashed()) || $row['jumlah'] != 0.0)
                ->values(),
            'riwayat' => auth()->user()->can('inventory.stock-card.view')
                ? $product->stockMovements()->with(['user', 'warehouse:id,name'])->orderByDesc('id')->limit(5)->get()
                : collect(),
        ]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('inventory.products.form', $this->dataForm($product));
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

    public function export(Request $request): Response
    {
        Gate::authorize('export', Product::class);
        $format = ListExport::formatFrom($request);

        return ProductsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('import', Product::class);

        return Excel::download(new ProductImportTemplate, 'template-impor-produk.xlsx');
    }

    public function importForm(): View
    {
        Gate::authorize('import', Product::class);

        return view('inventory.products.import');
    }

    public function import(ImportProductRequest $request): RedirectResponse
    {
        try {
            $pembaca = new ProductsImport;
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
