<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\CashSessionService;
use App\Services\Settings\TaxSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar Kasir (layout pos) beserta pencarian produk dan pelanggannya.
 * Stok yang tampil = stok gudang penjualan: gudang sesi kasir, atau gudang bawaan cabang aktif.
 */
class PosController extends Controller
{
    public const PRODUCT_LIMIT = 15;

    public const CATEGORY_LIMIT = 60;

    public function __construct(private readonly CashSessionService $sessions) {}

    public function index(Request $request, TaxSettingsService $taxes): View
    {
        $user = $request->user();
        ['session' => $session, 'warehouse' => $warehouse] = $this->sessions->saleContext($user);
        $methods = PaymentMethod::untukPenjualan();

        return view('pos.index', [
            'methods' => array_values(array_filter($methods, fn (PaymentMethod $m) => $m !== PaymentMethod::Receivable)),
            'receivableEnabled' => in_array(PaymentMethod::Receivable, $methods, true),
            'tax' => $taxes->get(),
            'session' => $session,
            'sessionRequired' => $this->sessions->isRequired(),
            'warehouse' => $warehouse,
            'warehouses' => Warehouse::query()->active()->ordered()->get(['id', 'code', 'name']),
            'categories' => Category::query()
                ->whereHas('products', fn (Builder $q) => $q->aktif()->jual())
                ->withCount(['products' => fn (Builder $q) => $q->aktif()->jual()])
                ->orderBy('name')->get(['id', 'name']),
            'stokMinus' => (bool) config('pos.allow_negative_stock'),
            'tahanTersisa' => HeldSale::MAKS_PER_KASIR - HeldSale::where('user_id', $user->id)->count(),
        ]);
    }

    /**
     * Pencarian produk untuk kasir: nama/SKU/scan barcode, atau semua produk satu kategori.
     * Hanya produk jual yang aktif, tanpa harga beli. `exact` = barcode/SKU cocok persis (hasil scan).
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'integer'],
        ]);
        $kata = trim((string) ($filter['q'] ?? ''));
        $kategori = $filter['kategori'] ?? null;

        if ($kata === '' && $kategori === null) {
            return response()->json([]);
        }

        $produk = Product::aktif()->jual()->with('unit:id,name')
            ->when($kategori, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($kata !== '', fn (Builder $q) => $q->cari($kata)
                ->orderByRaw('CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END', [$kata, $kata]))
            ->orderBy('name')
            ->limit($kata === '' ? self::CATEGORY_LIMIT : self::PRODUCT_LIMIT)
            ->get();

        $gudang = $this->sessions->saleContext($request->user())['warehouse'];
        $stok = WarehouseStock::query()->where('warehouse_id', $gudang->getKey())
            ->whereIn('product_id', $produk->modelKeys())->pluck('quantity', 'product_id');

        return response()->json($produk->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'unit' => $p->unit?->name,
            'category_id' => $p->category_id,
            'stock' => round((float) ($stok[$p->id] ?? 0), 3),
            'selling_price' => (float) $p->selling_price,
            'discount_percent' => (float) $p->discount_percent,
            'photo' => $p->photoUrl(),
            'exact' => $kata !== '' && ($p->barcode === $kata || $p->sku === $kata),
        ])->values());
    }

    /** Pencarian pelanggan untuk kasir. "Umum" selalu ikut tampil agar mudah kembali ke default. */
    public function searchCustomers(Request $request): JsonResponse
    {
        $kata = trim((string) $request->query('q', ''));

        $query = Customer::query();
        $hasil = ($kata === '' ? $query : $query->cari($kata))
            ->orderByDesc('is_default')->orderBy('name')->limit(10)->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'is_default' => $c->is_default,
                'receivable_balance' => (float) $c->receivable_balance,
            ]);

        return response()->json($hasil);
    }
}
