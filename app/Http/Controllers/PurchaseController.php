<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\VoidPurchaseRequest;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $service)
    {
    }

    public function index(Request $request): View
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', 'exists:suppliers,id'],
            'pembayaran' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PaymentStatus::cases()))],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PurchaseStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        // Ringkasan hanya menghitung pembelian yang tidak dibatalkan.
        $aktif = Purchase::query()->filter($filter)->selesai();

        return view('purchases.index', [
            'pembelian' => Purchase::query()->with('supplier')->filter($filter)
                ->orderByDesc('purchase_date')->orderByDesc('id')->paginate(15)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'ringkasan' => [
                'total' => (float) (clone $aktif)->sum('grand_total'),
                'hutang' => (float) (clone $aktif)->sum('balance'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $lama = collect(old('items', []));
        $produk = Product::whereIn('id', $lama->pluck('product_id')->filter())->with('unit:id,name')->get()->keyBy('id');

        $items = $lama->map(fn ($i) => isset($produk[$i['product_id'] ?? null]) ? [
            'product_id' => (int) $i['product_id'],
            'sku' => $produk[$i['product_id']]->sku,
            'name' => $produk[$i['product_id']]->name,
            'unit' => $produk[$i['product_id']]->unit?->name,
            'quantity' => $i['quantity'] ?? '1',
            'unit_price' => $i['unit_price'] ?? '0',
            'discount' => $i['discount'] ?? '0',
        ] : null)->filter()->values()->all();

        return view('purchases.create', [
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'supplierDipilih' => $request->integer('supplier') ?: null,
            'metode' => PaymentMethod::untukPembelian(),
            'items' => $items,
        ]);
    }

    public function store(PurchaseRequest $request): RedirectResponse
    {
        $pembelian = $this->service->buat($request->validated(), $request->user());

        return redirect()->route('purchases.show', $pembelian)
            ->with('success', "Pembelian {$pembelian->number} tersimpan. Stok {$pembelian->items->count()} produk bertambah.");
    }

    public function show(Purchase $purchase): View
    {
        return view('purchases.show', [
            'pembelian' => $purchase->load(['supplier', 'user', 'voidedBy', 'items', 'payments.user']),
        ]);
    }

    public function void(VoidPurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->service->batalkan($purchase, $request->input('alasan'), $request->user());

        return redirect()->route('purchases.show', $purchase)
            ->with('success', "Pembelian {$purchase->number} dibatalkan dan stok dikembalikan.");
    }

    /** Pencarian produk untuk formulir (nama, SKU, atau barcode hasil scan). */
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
                'purchase_price' => (float) $p->purchase_price,
            ]);

        return response()->json($hasil);
    }
}
