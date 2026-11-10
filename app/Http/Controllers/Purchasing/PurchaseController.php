<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Exports\ListExport;
use App\Exports\Purchasing\PurchasesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\VoidPurchaseRequest;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Inventory\ProductSearch;
use App\Services\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Faktur pembelian: pembelian langsung tanpa PO (stok langsung masuk) dan faktur yang dibuat
 * otomatis oleh Penerimaan Barang. Faktur tidak bisa diubah atau dihapus, hanya dibatalkan.
 */
class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $service) {}

    public function index(Request $request): View
    {
        $filter = $this->filter($request);
        $list = PurchasesExport::fromRequest($request, $filter);

        // Ringkasan hanya menghitung faktur yang tidak dibatalkan.
        $aktif = fn () => Purchase::query()->filter($filter)->selesai();

        return view('purchases.index', [
            'pembelian' => $list->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name']),
            'ringkasan' => [
                'jumlah' => $aktif()->count(),
                'total' => (float) $aktif()->sum('grand_total'),
                'hutang' => (float) $aktif()->sum('balance'),
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return PurchasesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(Request $request, ProductSearch $search): View
    {
        return view('purchases.create', [
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'supplierDipilih' => $request->integer('supplier') ?: null,
            'gudang' => Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name']),
            'gudangUtama' => Warehouse::forCurrentBranch()->id,
            'metode' => PaymentMethod::untukPembelian(),
            'items' => $search->formLines(old('items', [])),
        ]);
    }

    public function store(PurchaseRequest $request): RedirectResponse
    {
        $pembelian = $this->service->buat($request->validated(), $request->user());

        return redirect()->route('purchases.show', $pembelian)
            ->with('success', "Pembelian {$pembelian->number} tersimpan. Stok {$pembelian->items->count()} produk bertambah di {$pembelian->warehouse->name}.");
    }

    public function show(Purchase $purchase): View
    {
        return view('purchases.show', [
            'pembelian' => $purchase->load([
                'supplier', 'warehouse', 'user', 'voidedBy', 'items', 'payments.user',
                'purchaseOrder:id,number', 'goodsReceipt:id,number,purchase_id',
                'returns' => fn ($q) => $q->orderByDesc('return_date')->orderByDesc('id'),
            ]),
            'metode' => PaymentMethod::untukPembelian(),
        ]);
    }

    public function void(VoidPurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->service->batalkan($purchase, $request->input('alasan'), $request->user());

        return redirect()->route('purchases.show', $purchase)
            ->with('success', "Pembelian {$purchase->number} dibatalkan dan stok dikembalikan.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', 'exists:suppliers,id'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'pembayaran' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PaymentStatus::cases()))],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PurchaseStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}
