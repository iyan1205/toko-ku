<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockOpnameRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockOpname;
use App\Services\StockOpnameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockOpnameController extends Controller
{
    public const BATAS_BARIS = 500;

    public function __construct(private readonly StockOpnameService $service)
    {
    }

    public function index(): View
    {
        return view('stock-opnames.index', [
            'opname' => StockOpname::query()
                ->with('user')
                ->withCount(['items', 'items as selisih_count' => fn ($q) => $q->where('difference', '!=', 0)])
                ->orderByDesc('opname_date')->orderByDesc('id')
                ->paginate(15),
        ]);
    }

    public function create(Request $request): View
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $query = Product::aktif()->with(['unit:id,name', 'category:id,name'])->filter($filter)->orderBy('name');

        return view('stock-opnames.create', [
            'produk' => (clone $query)->limit(self::BATAS_BARIS)->get(),
            // reorder(): COUNT tanpa ORDER BY agar aman di MySQL dengan ONLY_FULL_GROUP_BY.
            'terpotong' => (clone $query)->reorder()->count() > self::BATAS_BARIS,
            'kategori' => Category::orderBy('name')->get(['id', 'name']),
            'batas' => self::BATAS_BARIS,
        ]);
    }

    public function store(StockOpnameRequest $request): RedirectResponse
    {
        $opname = $this->service->simpan($request->validated(), $request->user());

        return redirect()->route('stock-opnames.show', $opname)
            ->with('success', "Stok opname {$opname->number} tersimpan.");
    }

    public function show(StockOpname $stockOpname): View
    {
        return view('stock-opnames.show', [
            'opname' => $stockOpname->load(['user', 'items' => fn ($q) => $q->with('product')->orderBy('id')]),
        ]);
    }
}
