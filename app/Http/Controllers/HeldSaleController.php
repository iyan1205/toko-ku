<?php

namespace App\Http\Controllers;

use App\Exceptions\AturanBisnisException;
use App\Http\Requests\HoldSaleRequest;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Transaksi yang ditahan kasir (F9). Hanya milik kasir yang bersangkutan, tidak bisa diakses kasir lain. */
class HeldSaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $daftar = HeldSale::with('customer:id,name,is_default')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (HeldSale $h) => [
                'id' => $h->id,
                'label' => $h->label ?: ('Tahan #' . $h->id),
                'customer' => $h->customer?->name ?? 'Umum',
                'items' => count($h->cart['items'] ?? []),
                'created_at' => $h->created_at->format('H:i'),
            ]);

        return response()->json($daftar);
    }

    public function store(HoldSaleRequest $request): JsonResponse
    {
        $user = $request->user();
        $sisa = HeldSale::MAKS_PER_KASIR - HeldSale::where('user_id', $user->id)->count();

        if ($sisa <= 0) {
            throw new AturanBisnisException(
                'Transaksi ditahan sudah mencapai batas maksimal (' . HeldSale::MAKS_PER_KASIR . '). Selesaikan atau hapus salah satu dahulu.'
            );
        }

        $data = $request->validated();

        $tahan = HeldSale::create([
            'user_id' => $user->id,
            'customer_id' => $data['customer_id'] ?? null,
            'label' => $data['label'] ?? null,
            'cart' => [
                'items' => $data['items'],
                'discount' => $data['discount'] ?? 0,
                'note' => $data['note'] ?? null,
            ],
        ]);

        return response()->json(['id' => $tahan->id, 'label' => $tahan->label ?: ('Tahan #' . $tahan->id)], 201);
    }

    public function show(Request $request, HeldSale $heldSale): JsonResponse
    {
        Gate::allowIf($heldSale->user_id === $request->user()->id);

        $ids = collect($heldSale->cart['items'] ?? [])->pluck('product_id');
        $produk = Product::whereIn('id', $ids)->with('unit:id,name')->get()->keyBy('id');

        $items = collect($heldSale->cart['items'] ?? [])->map(function ($it) use ($produk) {
            $p = $produk->get($it['product_id']);

            return $p ? [
                'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'unit' => $p->unit?->name,
                'quantity' => $it['quantity'], 'unit_price' => (float) $p->selling_price, 'discount' => $it['discount'] ?? 0,
                'stock' => (float) $p->stock,
            ] : null;
        })->filter()->values();

        $hilang = count($heldSale->cart['items'] ?? []) - $items->count();
        $pelanggan = $heldSale->customer;

        return response()->json([
            'customer' => $pelanggan ? [
                'id' => $pelanggan->id, 'name' => $pelanggan->name,
                'is_default' => $pelanggan->is_default, 'receivable_balance' => (float) $pelanggan->receivable_balance,
            ] : null,
            'discount' => $heldSale->cart['discount'] ?? 0,
            'note' => $heldSale->cart['note'] ?? null,
            'items' => $items,
            'produk_hilang' => $hilang,
        ]);
    }

    public function destroy(Request $request, HeldSale $heldSale): JsonResponse
    {
        Gate::allowIf($heldSale->user_id === $request->user()->id);

        $heldSale->delete();

        return response()->json(['deleted' => true]);
    }
}
