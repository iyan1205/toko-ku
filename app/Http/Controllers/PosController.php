<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        return view('pos.index', [
            'metode' => PaymentMethod::untukPenjualan(),
            'urlCariProduk' => route('pos.products'),
            'urlCariPelanggan' => route('pos.customers'),
            'urlTahan' => route('pos.hold.store'),
            'urlDaftarTahan' => route('pos.hold.index'),
            'urlSimpanJual' => route('sales.store'),
            'urlPanggilTahan' => route('pos.hold.show', '__ID__'),
            'urlHapusTahan' => route('pos.hold.destroy', '__ID__'),
            'stokMinus' => (bool) config('pos.allow_negative_stock'),
            'tahanTersisa' => HeldSale::MAKS_PER_KASIR - HeldSale::where('user_id', $request->user()->id)->count(),
        ]);
    }

    /** Pencarian produk untuk kasir: nama/SKU/scan barcode. Hanya produk aktif, tanpa harga beli. */
    public function searchProducts(Request $request): JsonResponse
    {
        $kata = trim((string) $request->query('q', ''));

        if ($kata === '') {
            return response()->json([]);
        }

        $hasil = Product::aktif()->with('unit:id,name')->cari($kata)
            ->orderByRaw('CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END', [$kata, $kata])
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'name' => $p->name,
                'unit' => $p->unit?->name,
                'stock' => (float) $p->stock,
                'selling_price' => (float) $p->selling_price,
                'discount_percent' => (float) $p->discount_percent,
            ]);

        return response()->json($hasil);
    }

    /** Pencarian pelanggan untuk kasir. "Umum" selalu ikut tampil agar mudah kembali ke default. */
    public function searchCustomers(Request $request): JsonResponse
    {
        $kata = trim((string) $request->query('q', ''));

        $query = Customer::query();
        $hasil = ($kata === '' ? $query : $query->cari($kata))
            ->orderByDesc('is_default')->orderBy('name')->limit(10)->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_default' => $c->is_default,
                'receivable_balance' => (float) $c->receivable_balance,
            ]);

        return response()->json($hasil);
    }
}
