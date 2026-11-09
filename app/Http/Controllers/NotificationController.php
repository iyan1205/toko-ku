<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Format;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Isi dropdown notifikasi di topbar (JSON), diambil saat lonceng dibuka.
 * Saat ini berisi produk yang perlu diisi ulang (stok menipis atau habis).
 */
class NotificationController extends Controller
{
    /** Jumlah produk yang ditampilkan di dropdown. */
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Menu::allows('inventory.stock.view', $request->user()), 403);

        $products = Product::query()->needsRestock()
            ->with('unit:id,name')
            ->orderBy('stock')->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'stock', 'min_stock', 'unit_id']);

        return response()->json([
            'count' => Product::query()->needsRestock()->count(),
            'items' => $products->map(fn (Product $product) => [
                'title' => $product->name,
                'subtitle' => sprintf(
                    'Sisa %s %s · minimum %s',
                    Format::jumlah($product->stock),
                    $product->unit?->name ?? '',
                    Format::jumlah($product->min_stock),
                ),
                'status' => (float) $product->stock <= 0 ? 'habis' : 'menipis',
                'url' => route('stock.index', ['cari' => $product->name]),
            ]),
        ]);
    }
}
