<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Pencarian global di topbar. Setiap kelompok hasil hanya muncul bila pengguna boleh
 * membuka halamannya, dan kasir hanya menemukan penjualan miliknya sendiri.
 */
class SearchController extends Controller
{
    /** Hasil maksimal per kelompok. */
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $keyword = trim($request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ])['q']);

        $user = $request->user();

        $groups = collect([
            'Produk' => $this->products($keyword, $user),
            'Penjualan' => $this->sales($keyword, $user),
            'Pelanggan' => $this->customers($keyword, $user),
            'Pembelian' => $this->purchases($keyword, $user),
            'Supplier' => $this->suppliers($keyword, $user),
        ])
            ->filter(fn (?Collection $items) => $items !== null && $items->isNotEmpty())
            ->map(fn (Collection $items, string $label) => ['label' => $label, 'items' => $items->values()])
            ->values();

        return response()->json(['groups' => $groups]);
    }

    /** @return Collection<int, array{title: string, subtitle: string, url: string}>|null */
    private function products(string $keyword, User $user): ?Collection
    {
        if (Gate::forUser($user)->denies('viewAny', Product::class)) {
            return null;
        }

        return Product::query()->cari($keyword)->orderBy('name')->limit(self::LIMIT)
            ->get(['id', 'name', 'sku', 'selling_price'])
            ->map(fn (Product $product) => [
                'title' => $product->name,
                'subtitle' => $product->sku.' · '.Format::rupiah($product->selling_price),
                'url' => route('products.show', $product),
            ]);
    }

    /** @return Collection<int, array{title: string, subtitle: string, url: string}>|null */
    private function sales(string $keyword, User $user): ?Collection
    {
        if (Gate::forUser($user)->denies('viewAny', Sale::class)) {
            return null;
        }

        return Sale::query()
            ->with('customer:id,name')
            ->filter(['cari' => $keyword, 'kasir' => $user->can('view-sales') ? null : $user->id])
            ->orderByDesc('sold_at')->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'number', 'customer_id', 'grand_total', 'sold_at'])
            ->map(fn (Sale $sale) => [
                'title' => $sale->number,
                'subtitle' => ($sale->customer?->name ?? '-').' · '.Format::rupiah($sale->grand_total).' · '.Format::tanggal($sale->sold_at),
                'url' => route('sales.show', $sale),
            ]);
    }

    /** @return Collection<int, array{title: string, subtitle: string, url: string}>|null */
    private function customers(string $keyword, User $user): ?Collection
    {
        if (! $user->can('manage-customers')) {
            return null;
        }

        return Customer::query()->cari($keyword)->orderBy('name')->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'phone'])
            ->map(fn (Customer $customer) => [
                'title' => $customer->name,
                'subtitle' => trim($customer->code.' · '.($customer->phone ?? ''), ' ·'),
                'url' => route('customers.show', $customer),
            ]);
    }

    /** @return Collection<int, array{title: string, subtitle: string, url: string}>|null */
    private function purchases(string $keyword, User $user): ?Collection
    {
        if (! $user->can('view-purchases')) {
            return null;
        }

        return Purchase::query()
            ->with('supplier:id,name')
            ->filter(['cari' => $keyword])
            ->orderByDesc('purchase_date')->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'number', 'supplier_id', 'grand_total', 'purchase_date'])
            ->map(fn (Purchase $purchase) => [
                'title' => $purchase->number,
                'subtitle' => ($purchase->supplier?->name ?? '-').' · '.Format::rupiah($purchase->grand_total).' · '.Format::tanggal($purchase->purchase_date),
                'url' => route('purchases.show', $purchase),
            ]);
    }

    /** @return Collection<int, array{title: string, subtitle: string, url: string}>|null */
    private function suppliers(string $keyword, User $user): ?Collection
    {
        if (! $user->can('manage-suppliers')) {
            return null;
        }

        return Supplier::query()->cari($keyword)->orderBy('name')->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'phone'])
            ->map(fn (Supplier $supplier) => [
                'title' => $supplier->name,
                'subtitle' => trim($supplier->code.' · '.($supplier->phone ?? ''), ' ·'),
                'url' => route('suppliers.show', $supplier),
            ]);
    }
}
