<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanBisnisException;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $cari = $request->validate(['cari' => ['nullable', 'string', 'max:100']])['cari'] ?? null;

        $items = Category::query()
            ->withCount('products')
            ->when($cari, fn ($q, $kata) => $q->where('name', 'like', "%{$kata}%"))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('master.index', [
            'judul' => 'Kategori',
            'tunggal' => 'kategori',
            'prefix' => 'categories',
            'items' => $items,
        ]);
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
}
