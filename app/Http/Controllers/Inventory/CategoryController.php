<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Exceptions\AturanBisnisException;
use App\Exports\Inventory\CategoriesExport;
use App\Exports\ListExport;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        return view('inventory.master.index', [
            'judul' => 'Kategori',
            'tunggal' => 'kategori',
            'prefix' => 'categories',
            'izin' => 'inventory.categories',
            'items' => CategoriesExport::fromRequest($request, $this->filter($request))->paginate(15),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return CategoriesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $kategori = Category::create($request->validated());

        return back()->with('success', "Kategori {$kategori->name} berhasil ditambahkan.");
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return back()->with('success', "Kategori {$category->name} berhasil diperbarui.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($jumlah = $category->dipakaiProduk()) {
            throw new AturanBisnisException("Kategori {$category->name} tidak dapat dihapus karena masih dipakai {$jumlah} produk.");
        }

        $category->delete();

        return back()->with('success', "Kategori {$category->name} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate(['cari' => ['nullable', 'string', 'max:100']]);
    }
}
