<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseStatus;
use App\Exports\ListExport;
use App\Exports\Purchasing\GoodsReceiptsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\GoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Purchasing\GoodsReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Penerimaan barang dari purchase order. Izin purchasing.receipts.* di routes/modules/purchasing.php. */
class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(Request $request): View
    {
        return view('purchasing.receipts.index', [
            'receipts' => GoodsReceiptsExport::fromRequest($request, $this->filter($request))->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'gudang' => Warehouse::query()->ordered()->get(['id', 'name']),
            'menunggu' => PurchaseOrder::query()->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Partial->value])->count(),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return GoodsReceiptsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    /** Tanpa ?po: pilih PO yang menunggu barang. Dengan ?po: formulir penerimaan. */
    public function create(Request $request): View|RedirectResponse
    {
        $poId = $request->integer('po');

        if (! $poId) {
            return view('purchasing.receipts.pick', [
                'orders' => PurchaseOrder::query()
                    ->with(['supplier:id,name', 'warehouse:id,name'])
                    ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Partial->value])
                    ->when($request->input('cari'), fn ($q, $kata) => $q->filter(['cari' => $kata]))
                    ->orderBy('expected_date')->orderBy('order_date')
                    ->paginate(15)->withQueryString(),
            ]);
        }

        $order = PurchaseOrder::with(['supplier', 'warehouse', 'items'])->findOrFail($poId);

        if (! $order->status->canReceive()) {
            return redirect()->route('purchase-orders.show', $order)
                ->with('error', "Purchase order {$order->number} berstatus {$order->status->label()}, barangnya tidak bisa diterima.");
        }

        return view('purchasing.receipts.create', [
            'order' => $order,
            'gudang' => Warehouse::query()->active()->ordered()->get(['id', 'name']),
        ]);
    }

    public function store(GoodsReceiptRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $receipt = $this->receipts->create($purchaseOrder, $request->validated(), $request->user());

        return redirect()->route('goods-receipts.show', $receipt)
            ->with('success', "Penerimaan {$receipt->number} tersimpan. Stok bertambah dan hutang tercatat di faktur {$receipt->purchase->number}.");
    }

    public function show(GoodsReceipt $goodsReceipt): View
    {
        return view('purchasing.receipts.show', [
            'receipt' => $goodsReceipt->load(['purchaseOrder', 'supplier', 'warehouse', 'purchase', 'user', 'voidedBy', 'items.orderItem']),
        ]);
    }

    public function void(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan pembatalan']);
        $this->receipts->void($goodsReceipt, $request->input('alasan'), $request->user());

        return redirect()->route('goods-receipts.show', $goodsReceipt)
            ->with('success', "Penerimaan {$goodsReceipt->number} dibatalkan. Stok dikurangi kembali dan fakturnya dibatalkan.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', 'exists:suppliers,id'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, PurchaseStatus::cases()))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}
