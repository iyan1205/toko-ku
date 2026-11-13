<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Settings\StoreProfileService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Inventori → Cetak Label: label barcode (Code 128) dan harga, ukuran dari config/labels.php. */
class ProductLabelController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->validate(['cari' => ['nullable', 'string', 'max:100']]);

        return view('inventory.labels.index', [
            'products' => Product::aktif()->sellable()->with('unit:id,name')->cari($filter['cari'] ?? null)->orderBy('name')->paginate(30)->withQueryString(),
            'sizes' => collect(config('labels.sizes'))->map(fn ($s) => $s['label']),
            'filter' => $filter,
        ]);
    }

    public function print(Request $request, StoreProfileService $store): View
    {
        $data = $request->validate([
            'copies' => ['required', 'array'],
            'copies.*' => ['nullable', 'integer', 'min:0', 'max:500'],
            'size' => ['required', Rule::in(array_keys(config('labels.sizes')))],
            'layout' => ['required', Rule::in(['roll', 'a4'])],
            'show' => ['nullable', 'array'],
            'show.*' => [Rule::in(['name', 'price', 'sku', 'store'])],
        ], [], ['copies.*' => 'jumlah label']);

        $copies = collect($data['copies'])->map(fn ($n) => (int) $n)->filter(fn ($n) => $n > 0);
        abort_if($copies->isEmpty(), 422, 'Isi jumlah label minimal untuk satu produk.');
        abort_if($copies->sum() > config('labels.max_labels'), 422, 'Paling banyak '.config('labels.max_labels').' label sekali cetak.');

        $products = Product::query()->whereIn('id', $copies->keys())->get()->keyBy('id');
        $labels = $copies->flatMap(fn ($n, $id) => isset($products[$id]) ? array_fill(0, $n, $products[$id]) : [])->values();

        return view('inventory.labels.print', [
            'labels' => $labels,
            'size' => config('labels.sizes.'.$data['size']),
            'layout' => $data['layout'],
            'show' => $data['show'] ?? ['name', 'price'],
            'store' => $store->ambil()['nama_toko'] ?? '',
        ]);
    }
}
